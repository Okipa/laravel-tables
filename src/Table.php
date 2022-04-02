<?php

namespace Okipa\LaravelTable;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Okipa\LaravelTable\Abstracts\AbstractColumnAction;
use Okipa\LaravelTable\Abstracts\AbstractHeadAction;
use Okipa\LaravelTable\Abstracts\AbstractRowAction;
use Okipa\LaravelTable\Exceptions\NoColumnsDeclared;

class Table
{
    protected Model $model;

    protected bool $numberOfRowsPerPageChoiceEnabled;

    protected array $numberOfRowsPerPageOptions;

    protected AbstractHeadAction|null $headAction = null;

    protected Closure|null $rowActionsClosure = null;

    protected Closure|null $queryClosure = null;

    protected Closure|null $rowClassesClosure = null;

    protected Collection $columns;

    protected LengthAwarePaginator $rows;

    public function __construct()
    {
        $this->numberOfRowsPerPageChoiceEnabled = config('laravel-table.enable_number_of_rows_per_page_choice');
        $this->numberOfRowsPerPageOptions = config('laravel-table.number_of_rows_per_page_options');
        $this->columns = collect();
    }

    public static function make(): self
    {
        return new static();
    }

    public function model(string $modelClass): self
    {
        $this->model = app($modelClass);

        return $this;
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

    public function headAction(AbstractHeadAction $headAction): self
    {
        $this->headAction = $headAction;

        return $this;
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

    public function rowActions(Closure $rowActionsClosure): self
    {
        $this->rowActionsClosure = $rowActionsClosure;

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

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function getColumnSortedByDefault(): Column|null
    {
        $sortableColumns = $this->getColumns()->filter(fn(Column $column) => $column->isSortable());
        if ($sortableColumns->isEmpty()) {
            return null;
        }
        $columnSortedByDefault = $sortableColumns->filter(fn(Column $column) => $column->isSortedByDefault())->first();
        if (! $columnSortedByDefault) {
            return $sortableColumns->first();
        }

        return $columnSortedByDefault;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function getColumns(): Collection
    {
        if ($this->columns->isEmpty()) {
            throw new NoColumnsDeclared($this->model);
        }

        return $this->columns;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function getColumn(string $key): Column
    {
        return $this->getColumns()->filter(fn(Column $column) => $column->getKey() === $key)->first();
    }

    public function query(Closure $queryClosure): self
    {
        $this->queryClosure = $queryClosure;

        return $this;
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    public function generateRows(
        string|null $searchBy,
        string|Closure|null $sortBy,
        string|null $sortDir,
        int $numberOfRowsPerPage,
    ): void {
        $query = $this->model->query();
        // Query
        if ($this->queryClosure) {
            ($this->queryClosure)($query);
        }
        // Search
        if ($searchBy) {
            $this->getSearchableColumns()->each(function (Column $searchableColumn) use ($query, $searchBy) {
                $searchableClosure = $searchableColumn->getSearchableClosure();
                $searchableClosure
                    ? $query->orWhere(fn(Builder $orWhereQuery) => ($searchableClosure)($orWhereQuery, $searchBy))
                    : $query->orWhere(
                        DB::raw('LOWER(' . $searchableColumn->getKey() . ')'),
                        $this->getCaseInsensitiveSearchingLikeOperator(),
                        '%' . mb_strtolower($searchBy) . '%'
                    );
            });
        }
        // Sort
        if ($sortBy && $sortDir) {
            $sortBy instanceof Closure
                ? $sortBy($query, $sortDir)
                : $query->orderBy($sortBy, $sortDir);
        }
        // Paginate
        $this->rows = $query->paginate($numberOfRowsPerPage);
    }

    /** @throws \Okipa\LaravelTable\Exceptions\NoColumnsDeclared */
    protected function getSearchableColumns(): Collection
    {
        return $this->getColumns()->filter(fn(Column $column) => $column->isSearchable());
    }

    protected function getCaseInsensitiveSearchingLikeOperator(): string
    {
        $connection = config('database.default');
        $driver = config('database.connections.' . $connection . '.driver');

        return $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    public function getHeadActionArray(): array|null
    {
        if (! $this->headAction) {
            return null;
        }
        $this->headAction->setup();

        return (array) $this->headAction;
    }

    public function getRowClass(): array
    {
        $tableRowClass = [];
        if (! $this->rowClassesClosure) {
            return $tableRowClass;
        }
        foreach ($this->rows->getCollection() as $row) {
            $tableRowClass[$row->getKey()] = ($this->rowClassesClosure)($row);
        }

        return $tableRowClass;
    }

    public function generateRowActionsArray(): array
    {
        $tableRowActionsArray = [];
        if (! $this->rowActionsClosure) {
            return $tableRowActionsArray;
        }
        foreach ($this->rows->getCollection() as $model) {
            $rowActions = collect(($this->rowActionsClosure)($model))
                ->filter(fn(AbstractRowAction $rowAction) => $rowAction->isAllowed());
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
     * @throws \JsonException
     */
    public function generateColumnActionsArray(): array
    {
        $tableColumnActionsArray = [];
        foreach ($this->rows->getCollection() as $model) {
            $columnActions = $this->getColumns()
                ->mapWithKeys(fn(Column $column) => [$column->getKey() => $column->getAction()
                    ? $column->getAction()($model)
                    : null])
                ->filter(fn(AbstractColumnAction|null $columnAction) => $columnAction?->isAllowed());
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
            ->map(fn(Column $searchableColumn) => ['title' => $searchableColumn->getTitle()])
            ->implode('title', ', ');
    }

    public function getRows(): LengthAwarePaginator
    {
        return $this->rows;
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
