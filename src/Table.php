<?php

namespace Okipa\LaravelTable;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonException;
use Okipa\LaravelTable\Abstracts\AbstractFilter;
use Okipa\LaravelTable\Abstracts\AbstractHeadAction;
use Okipa\LaravelTable\Abstracts\AbstractRowAction;
use Okipa\LaravelTable\Exceptions\NoColumnsDeclared;

/** @SuppressWarnings(PHPMD.ExcessiveClassComplexity) */
class Table
{
    protected Model $model;

    protected array $eventsToEmitOnLoad = [];

    protected null|Column $orderColumn = null;

    protected bool $numberOfRowsPerPageChoiceEnabled;

    protected array $numberOfRowsPerPageOptions;

    protected array $filters = [];

    protected null|AbstractHeadAction $headAction = null;

    protected null|Closure $rowActionsClosure = null;

    protected null|Closure $bulkActionsClosure = null;

    protected null|Closure $queryClosure = null;

    protected null|Closure $rowClassesClosure = null;

    protected Collection $columns;

    protected Collection $results;

    protected LengthAwarePaginator $rows;

    public function __construct()
    {
        $this->numberOfRowsPerPageChoiceEnabled = config('laravel-table.enable_number_of_rows_per_page_choice');
        $this->numberOfRowsPerPageOptions = config('laravel-table.number_of_rows_per_page_default_options');
        $this->columns = collect();
        $this->results = collect();
    }

    public function model(string $modelClass): self
    {
        $this->model = app($modelClass);

        return $this;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function emitEventsOnLoad(array $eventsToEmitOnLoad): self
    {
        $this->eventsToEmitOnLoad = $eventsToEmitOnLoad;

        return $this;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\InvalidColumnSortDirection */
    public function reorderable(string $attribute, string $title = null, string $sortDirByDefault = 'asc'): self
    {
        $orderColumn = Column::make($attribute)->sortable()->sortByDefault($sortDirByDefault);
        if ($title) {
            $orderColumn->title($title);
        }
        $this->orderColumn = $orderColumn;

        return $this;
    }

    public static function make(): self
    {
        return new self();
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function prependReorderColumn(): void
    {
        $orderColumn = $this->getOrderColumn();
        if ($orderColumn) {
            $this->getColumns()->prepend($orderColumn);
        }
    }

    public function getOrderColumn(): null|Column
    {
        return $this->orderColumn;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function getColumns(): Collection
    {
        if ($this->columns->isEmpty()) {
            throw new NoColumnsDeclared($this->model);
        }

        return $this->columns;
    }

    public function getReorderConfig(null|string $sortDir): array
    {
        if (! $this->getOrderColumn()) {
            return [];
        }
        $query = $this->model->query();
        // Query
        if ($this->queryClosure) {
            $query->where(fn ($subQueryQuery) => ($this->queryClosure)($query));
        }
        // Sort
        $query->orderBy($this->getOrderColumn()->getAttribute(), $sortDir);

        return [
            'modelClass' => $this->model::class,
            'reorderAttribute' => $this->getOrderColumn()->getAttribute(),
            'sortDir' => $sortDir,
            'beforeReorderAllModelKeysWithPosition' => $query
                ->get()
                ->map(fn (Model $model) => [
                    'modelKey' => (string) $model->getKey(),
                    'position' => $model->getAttribute($this->getOrderColumn()->getAttribute()),
                ])
                ->toArray(),
        ];
    }

    public function query(Closure $queryClosure): self
    {
        $this->queryClosure = $queryClosure;

        return $this;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function prepareQuery(
        array $filterClosures,
        null|string $searchBy,
        string|Closure|null $sortBy,
        null|string $sortDir
    ): Builder {
        $query = $this->model->query();
        // Query
        if ($this->queryClosure) {
            $query->where(fn (Builder $subQueryQuery) => ($this->queryClosure)($query));
        }
        // Filters
        if ($filterClosures) {
            $query->where(function (Builder $subFiltersQuery) use ($filterClosures) {
                foreach ($filterClosures as $filterClosure) {
                    $filterClosure($subFiltersQuery);
                }
            });
        }
        // Search
        if ($searchBy) {
            $query->where(function (Builder $subSearchQuery) use ($searchBy) {
                $this->getSearchableColumns()
                    ->each(function (Column $searchableColumn) use ($subSearchQuery, $searchBy) {
                        $searchableClosure = $searchableColumn->getSearchableClosure();
                        $searchableClosure
                            ? $subSearchQuery->orWhere(fn (Builder $orWhereQuery) => ($searchableClosure)(
                                $orWhereQuery,
                                $searchBy
                            ))
                            : $subSearchQuery->orWhereRaw(
                                $this->getSearchSqlStatement($searchableColumn->getAttribute()),
                                ['%' . Str::of($searchBy)->trim()->lower() . '%']
                            );
                    });
            });
        }
        // Sort
        if ($sortBy && $sortDir) {
            $sortBy instanceof Closure
                ? $sortBy($query, $sortDir)
                : $query->orderBy($sortBy, $sortDir);
        }

        return $query;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    protected function getSearchableColumns(): Collection
    {
        return $this->getColumns()->filter(fn (Column $column) => $column->isSearchable());
    }

    protected function getSearchSqlStatement(string $attribute): string
    {
        $connection = config('database.default');
        $driver = config('database.connections.' . $connection . '.driver');

        return $this->getSqlLowerFunction($driver, $attribute) . ' '
            . $this->getSqlCaseInsensitiveSearchingLikeOperator($driver) . ' ?';
    }

    protected function getSqlLowerFunction(string $driver, string $attribute): string
    {
        return $driver === 'pgsql' ? 'LOWER(CAST(' . $attribute . ' AS TEXT))' : 'LOWER(' . $attribute . ')';
    }

    protected function getSqlCaseInsensitiveSearchingLikeOperator(string $driver): string
    {
        return $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    public function getRows(): LengthAwarePaginator
    {
        return $this->rows;
    }

    public function triggerEventsEmissionOnLoad(Livewire\Table $table): void
    {
        foreach ($this->eventsToEmitOnLoad as $event => $params) {
            $eventName = is_string($event) ? $event : $params;
            $eventParams = is_array($params) ? $params : [];
            $table->dispatch($eventName, $eventParams);
        }
    }

    public function enableNumberOfRowsPerPageChoice(bool $numberOfRowsPerPageChoiceEnabled): self
    {
        $this->numberOfRowsPerPageChoiceEnabled = $numberOfRowsPerPageChoiceEnabled;

        return $this;
    }

    public function isNumberOfRowsPerPageChoiceEnabled(): bool
    {
        return $this->numberOfRowsPerPageChoiceEnabled;
    }

    public function numberOfRowsPerPageOptions(array $numberOfRowsPerPageOptions): self
    {
        $this->numberOfRowsPerPageOptions = $numberOfRowsPerPageOptions;

        return $this;
    }

    public function getNumberOfRowsPerPageOptions(): array
    {
        return $this->numberOfRowsPerPageOptions;
    }

    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function headAction(AbstractHeadAction $headAction): self
    {
        $this->headAction = $headAction;

        return $this;
    }

    public function rowActions(Closure $rowActionsClosure): self
    {
        $this->rowActionsClosure = $rowActionsClosure;

        return $this;
    }

    public function bulkActions(Closure $bulkActionsClosure): self
    {
        $this->bulkActionsClosure = $bulkActionsClosure;

        return $this;
    }

    public function rowClass(Closure $rowClassesClosure): self
    {
        $this->rowClassesClosure = $rowClassesClosure;

        return $this;
    }

    public function columns(array $columns): void
    {
        $this->columns = collect($columns);
    }

    public function results(array $results): void
    {
        $this->results = collect($results);
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function getColumnSortedByDefault(): null|Column
    {
        $sortableColumns = $this->getColumns()
            ->filter(fn (Column $column) => $column->isSortable($this->getOrderColumn()));
        if ($sortableColumns->isEmpty()) {
            return null;
        }
        $columnSortedByDefault = $sortableColumns->filter(fn (Column $column) => $column->isSortedByDefault())->first();
        if (! $columnSortedByDefault) {
            return $sortableColumns->first();
        }

        return $columnSortedByDefault;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function getColumn(string $attribute): Column
    {
        return $this->getColumns()->filter(fn (Column $column) => $column->getAttribute() === $attribute)->first();
    }

    public function paginateRows(Builder $query, int $numberOfRowsPerPage): void
    {
        $this->rows = $query->paginate($numberOfRowsPerPage);
        $this->rows->transform(function (Model $model) {
            $model->laravel_table_unique_identifier = Str::uuid()->getInteger()->toString();

            return $model;
        });
    }

    public function computeResults(Collection $displayedRows): void
    {
        $this->results = $this->results->map(fn (Result $result) => $result->compute(
            $this->model->query()->toBase(),
            $displayedRows,
        ));
    }

    public function generateFiltersArray(): array
    {
        return collect($this->filters)->map(function (AbstractFilter $filter) {
            $filter->setup($this->model->getKeyName());

            return json_decode(json_encode(
                $filter,
                JSON_THROW_ON_ERROR
            ), true, 512, JSON_THROW_ON_ERROR);
        })->toArray();
    }

    public function getFilterClosures(array $filtersArray, array $selectedFilters): array
    {
        $filterClosures = [];
        foreach ($selectedFilters as $identifier => $value) {
            if ($value === '' || $value === []) {
                continue;
            }
            $filterArray = AbstractFilter::retrieve($filtersArray, $identifier);
            $filterInstance = AbstractFilter::make($filterArray);
            $filterClosures[$identifier] = static fn (Builder $query) => $filterInstance->filter($query, $value);
        }

        return $filterClosures;
    }

    public function getHeadActionArray(): array
    {
        if (! $this->headAction) {
            return [];
        }
        $this->headAction->setup();
        if (! $this->headAction->isAllowed()) {
            return [];
        }

        return (array) $this->headAction;
    }

    /** @throws JsonException */
    public function getRowClass(): array
    {
        $tableRowClass = [];
        if (! $this->rowClassesClosure) {
            return $tableRowClass;
        }
        foreach ($this->rows->getCollection() as $model) {
            $tableRowClass[$model->laravel_table_unique_identifier] = ($this->rowClassesClosure)($model);
        }

        return json_decode(json_encode(
            $tableRowClass,
            JSON_THROW_ON_ERROR
        ), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @throws JsonException */
    public function generateBulkActionsArray(array $selectedModelKeys): array
    {
        $tableBulkActionsArray = [];
        $tableRawBulkActionsArray = [];
        if (! $this->bulkActionsClosure) {
            return $tableBulkActionsArray;
        }
        $bulkActionsModelKeys = [];
        foreach ($this->rows as $index => $model) {
            $modelBulkActions = collect(($this->bulkActionsClosure)($model));
            foreach ($modelBulkActions as $modelBulkAction) {
                $modelBulkAction->setup($model);
                if (! $index) {
                    $tableRawBulkActionsArray[] = json_decode(json_encode(
                        $modelBulkAction,
                        JSON_THROW_ON_ERROR
                    ), true, 512, JSON_THROW_ON_ERROR);
                }
                if (! in_array((string) $model->getKey(), $selectedModelKeys, true)) {
                    continue;
                }
                $modelBulkAction->isAllowed()
                    ? $bulkActionsModelKeys[$modelBulkAction->identifier]['allowed'][] = $model->getKey()
                    : $bulkActionsModelKeys[$modelBulkAction->identifier]['disallowed'][] = $model->getKey();
            }
        }
        foreach ($tableRawBulkActionsArray as $tableBulkActionArray) {
            $identifier = $tableBulkActionArray['identifier'];
            $tableBulkActionArray['allowedModelKeys'] = $bulkActionsModelKeys[$identifier]['allowed'] ?? [];
            $tableBulkActionArray['disallowedModelKeys'] = $bulkActionsModelKeys[$identifier]['disallowed'] ?? [];
            $tableBulkActionsArray[] = $tableBulkActionArray;
        }

        return $tableBulkActionsArray;
    }

    public function generateRowActionsArray(): array
    {
        $tableRowActionsArray = [];
        if (! $this->rowActionsClosure) {
            return $tableRowActionsArray;
        }
        foreach ($this->rows->getCollection() as $model) {
            $rowActions = collect(($this->rowActionsClosure)($model))
                ->filter(fn (AbstractRowAction $rowAction) => $rowAction->isAllowed());
            $rowActionsArray = $rowActions->map(static function (AbstractRowAction $rowAction) use ($model) {
                $rowAction->setup($model);

                return json_decode(json_encode(
                    $rowAction,
                    JSON_THROW_ON_ERROR
                ), true, 512, JSON_THROW_ON_ERROR);
            })->toArray();
            $tableRowActionsArray = [...$tableRowActionsArray, ...$rowActionsArray];
        }

        return $tableRowActionsArray;
    }

    /**
     * @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared
     * @throws JsonException
     */
    public function generateColumnActionsArray(): array
    {
        $tableColumnActionsArray = [];
        foreach ($this->rows->getCollection() as $model) {
            $columnActions = $this->getColumns()
                ->mapWithKeys(fn (Column $column) => [
                    $column->getAttribute() => $column->getAction()
                        ? $column->getAction()($model)
                        : null,
                ])
                ->filter();
            foreach ($columnActions as $attribute => $columnAction) {
                $columnAction->setup($model, $attribute);
                $tableColumnActionsArray[] = json_decode(json_encode(
                    $columnAction,
                    JSON_THROW_ON_ERROR
                ), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $tableColumnActionsArray;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function getSearchableLabels(): string
    {
        return $this->getSearchableColumns()
            ->map(fn (Column $searchableColumn) => ['title' => $searchableColumn->getTitle()])
            ->implode('title', ', ');
    }

    public function getResults(): Collection
    {
        return $this->results;
    }

    public function getNavigationStatus(): string
    {
        return __('Showing results <b>:start</b> to <b>:stop</b> on <b>:total</b>', [
            'start' => $this->rows->isNotEmpty()
                ? ($this->rows->perPage() * ($this->rows->currentPage() - 1)) + 1
                : 0,
            'stop' => $this->rows->count() + (($this->rows->currentPage() - 1) * $this->rows->perPage()),
            'total' => $this->rows->total(),
        ]);
    }
}
