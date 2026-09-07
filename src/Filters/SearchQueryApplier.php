<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiCore\Filters;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Model;
use dcardenasl\Ci4ApiCore\Support\ApiConfigFacade;

/**
 * SearchQueryApplier
 *
 * Applies a search query to a CI4 Model or BaseBuilder.
 *
 * Reads three knobs from `config('Api')` when available:
 *   - searchEnabled       (bool, default true)
 *   - searchMinLength     (int, default 0)
 *   - searchUseFulltext   (bool, default true)
 *
 * Each lookup falls back to a safe default when `config('Api')` does not exist
 * or the property is missing — so a consumer that hasn't shipped a Config\Api
 * still gets a working search out of the box.
 *
 * Every path goes through a {@see SearchProfile}: callers passing a flat field
 * list get `SearchProfile::fulltextOnly()`, which is the previous behaviour
 * plus two defect fixes it inherits for free — MATCH degrades to LIKE when no
 * index covers the columns, instead of failing the request, and Boolean Mode
 * operators (`@` in particular) can no longer turn user input into a syntax
 * error.
 */
class SearchQueryApplier
{
    /**
     * MySQL Boolean Mode operators. Left in the query string they are either a
     * syntax error or a silent change of meaning, so user input never keeps
     * them.
     *
     * `@` matters most: it opens the `@distance` operator, which is why the
     * ordinary address `admin@example.com` was a fatal parse error.
     */
    private const BOOLEAN_MODE_OPERATORS = '/[+\-*"()~<>@]/';

    /**
     * @param Model|BaseBuilder $builder
     * @param list<string>      $searchableFields
     */
    public static function apply(
        Model|BaseBuilder $builder,
        string $query,
        array $searchableFields,
        bool $useFulltext = true,
    ): void {
        if ($searchableFields === [] || $query === '') {
            return;
        }

        $profile = $useFulltext
            ? SearchProfile::fulltextOnly($searchableFields)
            : new SearchProfile(like: $searchableFields);

        self::applyProfile($builder, $query, $profile);
    }

    /**
     * Apply a declarative profile.
     *
     * All buckets land inside a single `groupStart()`/`groupEnd()`, so the
     * search never leaks out and widens a constraint the caller already
     * applied.
     *
     * @param Model|BaseBuilder $target
     * @param string|null       $table  Needed to look up FULLTEXT indexes;
     *                                  derived from a Model when omitted.
     */
    public static function applyProfile(
        Model|BaseBuilder $target,
        string $query,
        SearchProfile $profile,
        ?string $table = null,
    ): void {
        $query = trim($query);

        if ($query === '') {
            return;
        }

        if (! ApiConfigFacade::bool('searchEnabled', true)) {
            return;
        }

        // `searchMinLength` exists to stop 1-2 character fragments from being
        // matched against free text. It is the wrong rule for a short-code
        // column: for `prefix`/`exact` buckets a two-character query is not a
        // fragment, it is the whole value — ISO language codes are always two
        // characters, so a global floor of 3 made the Languages filter
        // impossible to use and silently listed everything instead.
        //
        // Derive the rule from what the profile declares rather than applying
        // one number blindly: the floor gates the free-text buckets, and
        // whole-value lookups always run. A model that declares a short-code
        // column gets this without configuring anything.
        if (strlen($query) < ApiConfigFacade::int('searchMinLength', 0)) {
            $profile = $profile->wholeValueOnly();

            if ($profile === null) {
                return;
            }
        }

        // The deployment-wide kill switch, applied at the single point every
        // caller goes through: a model that declares a profile cannot opt
        // itself back into FULLTEXT.
        if (! ApiConfigFacade::bool('searchUseFulltext', true)) {
            $profile = $profile->withoutFulltext();
        }

        $builder = $target instanceof Model ? $target->builder() : $target;
        /** @var BaseConnection<mixed, mixed> $db */
        $db = $target instanceof Model ? $target->db : $target->db();
        $table ??= self::resolveTable($target);

        $fulltext = $profile->fulltext;
        $like     = $profile->like;

        // A FULLTEXT bucket with no index behind it becomes a LIKE bucket
        // rather than a failed request.
        if ($fulltext !== [] && ! FulltextIndexInspector::covers($db, $table, $fulltext)) {
            $like     = [...$like, ...$fulltext];
            $fulltext = [];
        }

        // MATCH() needs a term left after sanitising. "@@@" sanitises to
        // nothing, and `AGAINST('')` matches every row — degrade to LIKE so an
        // all-operator query narrows instead of widening.
        $fulltextTerm = self::booleanModeTerm($query);

        if ($fulltext !== [] && $fulltextTerm === '') {
            $like     = [...$like, ...$fulltext];
            $fulltext = [];
        }

        $builder->groupStart();
        $first = true;

        if ($fulltext !== []) {
            $columns = implode(', ', array_map(
                static fn (string $column): string => $db->protectIdentifiers($column, false, false),
                $fulltext,
            ));
            $builder->where(
                sprintf('MATCH(%s) AGAINST(%s IN BOOLEAN MODE)', $columns, $db->escape($fulltextTerm)),
                null,
                false,
            );
            $first = false;
        }

        foreach ($like as $column) {
            $first ? $builder->like($column, $query) : $builder->orLike($column, $query);
            $first = false;
        }

        foreach ($profile->prefix as $column) {
            $first ? $builder->like($column, $query, 'after') : $builder->orLike($column, $query, 'after');
            $first = false;
        }

        foreach ($profile->exact as $column) {
            $first ? $builder->where($column, $query) : $builder->orWhere($column, $query);
            $first = false;
        }

        $builder->groupEnd();
    }

    /**
     * @param Model|BaseBuilder $builder
     * @param list<string>      $searchableFields
     */
    public static function applyFulltext(
        Model|BaseBuilder $builder,
        string $query,
        array $searchableFields,
    ): void {
        if ($searchableFields === []) {
            return;
        }

        self::applyProfile($builder, $query, SearchProfile::fulltextOnly($searchableFields));
    }

    /**
     * @param Model|BaseBuilder $builder
     * @param list<string>      $searchableFields
     */
    public static function applyLike(
        Model|BaseBuilder $builder,
        string $query,
        array $searchableFields,
    ): void {
        if ($searchableFields === []) {
            return;
        }

        self::applyProfile($builder, $query, new SearchProfile(like: $searchableFields));
    }

    /**
     * Strip Boolean Mode operators so user input cannot alter query semantics.
     *
     * Exposed publicly so consumers can pre-sanitize queries before other uses;
     * it does only this, and the operator set is the only thing that changed —
     * `@` joined it, because it opens `@distance` and made an ordinary email
     * address a syntax error.
     */
    public static function sanitizeFulltextQuery(string $query): string
    {
        return preg_replace(self::BOOLEAN_MODE_OPERATORS, ' ', $query) ?? $query;
    }

    /**
     * The AGAINST() term for a user query: sanitised, whitespace-collapsed, and
     * with a trailing `*` per word.
     *
     * The `*` is the one operator worth keeping, and it has to be added by us
     * rather than accepted from the user. Without it these are exact word
     * matches, so a list filter typed one character at a time shows nothing
     * until the last keystroke: "Espa" would not find "Español". The LIKE
     * buckets are already substring matches, and the prefix makes the FULLTEXT
     * bucket behave the same way from the reader's side.
     *
     * Returns '' when nothing survives — the caller degrades to LIKE rather
     * than emitting `AGAINST('')`, which matches every row.
     */
    private static function booleanModeTerm(string $query): string
    {
        $terms = preg_split('/\s+/', trim(self::sanitizeFulltextQuery($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(static fn (string $term): string => $term . '*', $terms));
    }

    /**
     * @param Model|BaseBuilder $target
     */
    private static function resolveTable(Model|BaseBuilder $target): string
    {
        if ($target instanceof Model) {
            /** @var \Closure(): string $read */
            $read = \Closure::bind(fn (): string => (string) $this->table, $target, $target);

            return $read();
        }

        return $target->getTable();
    }
}
