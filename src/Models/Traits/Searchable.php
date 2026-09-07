<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiCore\Models\Traits;

use dcardenasl\Ci4ApiCore\Filters\SearchProfile;
use dcardenasl\Ci4ApiCore\Filters\SearchQueryApplier;
use dcardenasl\Ci4ApiCore\Support\ApiConfigFacade;

/**
 * Searchable
 *
 * Adds search to a CI4 Model based on a `$searchableFields` whitelist.
 * Behavior is configurable via `config('Api')` (knobs: `searchEnabled`,
 * `searchUseFulltext`, `searchMinLength`); each knob defaults safely when the
 * config key is absent so a vanilla consumer still gets a working search.
 *
 * A model can override {@see self::searchProfile()} to state how each column is
 * searched — natural language, LIKE, anchored prefix or exact. Declaring
 * nothing keeps every searchable column as natural language, which is the
 * behaviour models had before profiles existed.
 *
 * @phpstan-require-extends \CodeIgniter\Model
 */
trait Searchable
{
    public function search(string $query): self
    {
        if (empty($this->searchableFields) || trim($query) === '') {
            return $this;
        }

        SearchQueryApplier::applyProfile($this, $query, $this->getSearchProfile(), $this->table);

        return $this;
    }

    /**
     * The profile this model is searched with, validated against its own
     * whitelist.
     *
     * Public because `QueryBuilder` — and therefore `BaseRepository`'s
     * pagination — has to reach it from outside the model.
     */
    public function getSearchProfile(): SearchProfile
    {
        $profile = $this->searchProfile();
        $profile->assertWhitelisted($this->searchableFields);

        return $profile;
    }

    /**
     * Override in a model to declare how each column is searched.
     *
     * The default treats every searchable column as natural language, which
     * degrades to LIKE on its own when no FULLTEXT index covers it.
     */
    protected function searchProfile(): SearchProfile
    {
        return SearchProfile::fulltextOnly($this->searchableFields);
    }

    /**
     * Whether this model would take the FULLTEXT path.
     *
     * Kept for consumers that branch on it. The applier no longer needs the
     * answer up front: it decides per bucket, per connection, from the indexes
     * that actually exist.
     */
    protected function useFulltextSearch(): bool
    {
        if (! ApiConfigFacade::bool('searchUseFulltext', true)) {
            return false;
        }

        $dbDriver = $this->db->DBDriver ?? '';
        if (! in_array(strtolower((string) $dbDriver), ['mysqli', 'mysql'], true)) {
            return false;
        }

        return ApiConfigFacade::bool('searchEnabled', true) && ! empty($this->searchableFields);
    }

    /** @return list<string> */
    public function getSearchableFields(): array
    {
        return $this->searchableFields;
    }

    public function isSearchable(string $field): bool
    {
        return in_array($field, $this->searchableFields, true);
    }
}
