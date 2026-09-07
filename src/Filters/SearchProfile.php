<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiCore\Filters;

use InvalidArgumentException;

/**
 * SearchProfile
 *
 * Declarative statement of how one model is searched, column by column.
 *
 * Without it, `SearchQueryApplier` puts every `$searchableFields` entry into a
 * single `MATCH() AGAINST()`, which assumes all of them are natural language
 * and that an index covers exactly that list. Two real failures came from that
 * assumption:
 *
 *   - an `email` column: `@` opens MySQL Boolean Mode's `@distance` operator,
 *     so `AGAINST('admin@example.com')` is a syntax error and the request 500s;
 *   - a table with no matching FULLTEXT index: MySQL rejects the statement
 *     outright with "Can't find FULLTEXT index matching the column list"
 *     rather than degrading.
 *
 * A profile separates those cases, and each bucket carries its own degradation
 * rule — see {@see SearchQueryApplier::applyProfile()}.
 *
 * Column names never come from user input: a profile is authored in PHP and
 * every column is checked against the model's own `$searchableFields`
 * whitelist before it reaches SQL.
 */
final class SearchProfile
{
    /**
     * @param list<string> $fulltext Natural-language columns. Matched with
     *                               MATCH/AGAINST when a FULLTEXT index covers
     *                               exactly this column list; LIKE otherwise.
     * @param list<string> $like     Columns holding punctuation-bearing values
     *                               (emails, slugs, URLs, keys). Always LIKE:
     *                               a FULLTEXT tokenizer mangles them.
     * @param list<string> $prefix   Short codes ("es", "en") that fall below
     *                               `innodb_ft_min_token_size` and can never
     *                               match FULLTEXT. Anchored prefix LIKE.
     * @param list<string> $exact    Columns compared verbatim.
     */
    public function __construct(
        public readonly array $fulltext = [],
        public readonly array $like = [],
        public readonly array $prefix = [],
        public readonly array $exact = [],
    ) {
        if ($this->columns() === []) {
            throw new InvalidArgumentException('A SearchProfile must declare at least one column.');
        }
    }

    /**
     * The profile a model gets when it declares none: every searchable column
     * treated as natural language.
     *
     * Preserves the behaviour models had before profiles existed, with the one
     * difference that a missing index now degrades to LIKE instead of failing.
     *
     * @param list<string> $searchableFields
     */
    public static function fulltextOnly(array $searchableFields): self
    {
        return new self(fulltext: $searchableFields);
    }

    /**
     * The same profile with FULLTEXT disabled, its natural-language columns
     * folded into the LIKE bucket.
     *
     * How the `searchUseFulltext` kill switch is honoured, so a model that
     * declares a profile still obeys the deployment-wide setting instead of
     * quietly overriding it.
     */
    public function withoutFulltext(): self
    {
        if ($this->fulltext === []) {
            return $this;
        }

        return new self(
            fulltext: [],
            like: [...$this->like, ...$this->fulltext],
            prefix: $this->prefix,
            exact: $this->exact,
        );
    }

    /**
     * Every column the profile touches, in bucket order.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return [...$this->fulltext, ...$this->like, ...$this->prefix, ...$this->exact];
    }

    /**
     * Reject any column the model has not whitelisted.
     *
     * Guards a typo or a column renamed out from under the profile — not user
     * input, which never reaches a column name.
     *
     * @param list<string> $searchableFields
     *
     * @throws InvalidArgumentException
     */
    public function assertWhitelisted(array $searchableFields): void
    {
        $unknown = array_values(array_diff($this->columns(), $searchableFields));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'SearchProfile references columns outside $searchableFields: %s',
                implode(', ', $unknown),
            ));
        }
    }
}
