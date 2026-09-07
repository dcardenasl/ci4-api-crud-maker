<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiCore\Filters;

use CodeIgniter\Model;
use dcardenasl\Ci4ApiCore\Repositories\RepositoryInterface;
use dcardenasl\Ci4ApiCore\Support\ApiConfigFacade;

/**
 * QueryBuilder
 *
 * Fluent wrapper around a CI4 Model that delegates filter / sort / search /
 * paginate concerns to FilterParser, FilterOperatorApplier, and
 * SearchQueryApplier. Used by RepositoryInterface implementations to assemble
 * paginated queries declaratively.
 *
 * Pagination knobs (`paginationDefaultLimit`, `paginationMaxLimit`) read from
 * `config('Api')` when available and fall back to sensible defaults
 * (20 / 100) so a vanilla consumer with no Config\Api still works.
 */
class QueryBuilder
{
    protected Model $model;

    /** @var array<string, array{0:string,1:mixed}> */
    protected array $filters = [];

    /** @var list<array{0:string,1:string}> */
    protected array $sorts = [];

    protected ?string $searchQuery = null;

    /**
     * @param Model|RepositoryInterface<object> $target
     */
    public function __construct(Model|RepositoryInterface $target)
    {
        if ($target instanceof RepositoryInterface) {
            $this->model = $target->getModel();
        } else {
            $this->model = $target;
        }
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filter(array $filters): self
    {
        if (property_exists($this->model, 'filterableFields') && !empty($this->model->filterableFields)) {
            $filters = FilterParser::filterAllowedFields($filters, $this->model->filterableFields);
        }

        $this->filters = FilterParser::parse($filters);

        foreach ($this->filters as $field => $condition) {
            [$operator, $value] = $condition;
            FilterOperatorApplier::apply($this->model, $field, $operator, $value);
        }

        return $this;
    }

    public function where(string $field, mixed $value): self
    {
        $this->model->where($field, $value);

        return $this;
    }

    /**
     * SECURITY: validates sort fields against the model's `$sortableFields`
     * whitelist before delegating to `orderBy()` so an attacker cannot inject
     * arbitrary column names through the `sort` query parameter.
     */
    public function sort(string $sort): self
    {
        $sortableFields = [];

        if (property_exists($this->model, 'sortableFields')) {
            $sortableFields = $this->model->sortableFields;
        }

        $parsedSorts = FilterParser::parseSort($sort, $sortableFields);

        foreach ($parsedSorts as [$field, $direction]) {
            $this->model->orderBy($field, $direction);
        }

        return $this;
    }

    public function search(string $query): self
    {
        $this->searchQuery = $query;

        // The model owns how it is searched. This used to re-derive the field
        // list from `$searchableFields` and call the applier itself, which meant
        // a model declaring a SearchProfile was honoured through
        // `Model::search()` and silently ignored on the pagination path that
        // `BaseRepository::paginateCriteria()` takes — two implementations of
        // the same policy, free to drift. Delegate instead.
        if (method_exists($this->model, 'search')) {
            $this->model->search($query);

            return $this;
        }

        $searchableFields = [];

        if (property_exists($this->model, 'searchableFields')) {
            $searchableFields = $this->model->searchableFields;
        }

        if (empty($searchableFields)) {
            return $this;
        }

        $useFulltext = ApiConfigFacade::bool('searchUseFulltext', true);

        SearchQueryApplier::apply($this->model, $query, $searchableFields, $useFulltext);

        return $this;
    }

    /**
     * @return array{data:list<mixed>, total:int, page:int, per_page:int, last_page:int, from:int, to:int}
     */
    public function paginate(int $page = 1, int $limit = 20): array
    {
        $defaultLimit = ApiConfigFacade::int('paginationDefaultLimit', 20);
        $maxLimit = ApiConfigFacade::int('paginationMaxLimit', 100);

        $limit = min($limit > 0 ? $limit : $defaultLimit, $maxLimit);
        $page = max($page, 1);

        $total = (int) $this->model->countAllResults(false);

        $offset = ($page - 1) * $limit;
        $last_page = $total > 0 ? (int) ceil($total / $limit) : 1;

        $data = $this->model->findAll($limit, $offset);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit,
            'last_page' => $last_page,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $limit, $total),
        ];
    }

    /** @return array<int,mixed> */
    public function get(): array
    {
        return $this->model->findAll();
    }

    public function first(): mixed
    {
        return $this->model->first();
    }

    public function count(): int
    {
        return (int) $this->model->countAllResults();
    }

}
