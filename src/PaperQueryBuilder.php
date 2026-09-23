<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use ReflectionMethod;
use Throwable;

/**
 * @template-covariant TModel of Model
 */
final class PaperQueryBuilder
{
    private const array OPERATORS = ['=', '==', '===', '!=', '<>', '!==', '>', '>=', '<', '<=', 'like'];

    /** @var list<array{type: string, column?: string, second?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool, wheres?: list<array<string, mixed>>, boolean: string}> */
    private array $wheres = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limitValue = null;

    private int $offsetValue = 0;

    private bool $randomOrder = false;

    /** @var ?TModel */
    private ?Model $model = null;

    /**
     * @param  class-string<TModel>  $modelClass
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly DriverContract $driver,
        private readonly CacheContract $cache,
        private readonly string $contentPath,
        private readonly string $modelClass,
    ) {}

    /**
     * Records get their own instance in fileToModel().
     *
     * @return TModel
     */
    private function model(): Model
    {
        return $this->model ??= new $this->modelClass;
    }

    /**
     * Rejects slugs that would escape the content directory.
     */
    public static function guardSlug(string $slug): void
    {
        $invalid = $slug === '.'
            || $slug === '..'
            || str_contains($slug, '/')
            || str_contains($slug, '\\')
            || str_contains($slug, "\0");

        if ($invalid) {
            throw InvalidSlugException::forSlug($slug);
        }
    }

    /**
     * @return ?TModel
     */
    public function find(string $slug): ?Model
    {
        $model = $this->locate($slug);

        if ($model !== null) {
            $this->fireRetrieved($model);
        }

        return $model;
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TModel|TValue
     */
    public function findOr(string $slug, Closure $callback): mixed
    {
        return $this->find($slug) ?? $callback();
    }

    /**
     * @param  array<int, scalar>  $ids
     * @return Collection<int, TModel>
     */
    public function findMany(array $ids): Collection
    {
        $models = [];

        foreach (array_unique(array_map(strval(...), $ids)) as $slug) {
            $model = $this->locate($slug);

            if ($model !== null) {
                $models[] = $model;
            }
        }

        $collection = $this->model()->newCollection($models);

        $collection->each($this->fireRetrieved(...));

        return $collection;
    }

    /**
     * @return ?TModel
     */
    private function locate(string $slug): ?Model
    {
        self::guardSlug($slug);

        foreach ($this->driver->extensions() as $ext) {
            $filepath = $this->contentPath.'/'.$slug.'.'.$ext;

            if ($this->files->exists($filepath)) {
                $model = $this->fileToModel($filepath);

                return $this->matchesWheres($model) ? $model : null;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>|(callable(static): mixed)|string  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function where(array|callable|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        if (! is_string($column)) {
            return $this->whereGroup($column, $boolean);
        }

        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        if ($value === null) {
            if (in_array($operator, ['=', '==', '==='], true)) {
                return $this->whereNull($column, $boolean);
            }

            if (in_array($operator, ['!=', '<>', '!=='], true)) {
                return $this->whereNotNull($column, $boolean);
            }
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $conditions
     */
    private function addArrayOfWheres(array $conditions, string $boolean): static
    {
        if ($conditions === []) {
            return $this;
        }

        return $this->whereGroup(function (self $query) use ($conditions): void {
            foreach ($conditions as $key => $value) {
                if (is_string($key)) {
                    $query->where($key, '=', $this->scalarOrNull($value));

                    continue;
                }

                if (! is_array($value) || ! is_string($value[0] ?? null) || count($value) < 2 || count($value) > 3) {
                    throw new InvalidArgumentException('Each array condition must be [column, value] or [column, operator, value].');
                }

                $column = $value[0];

                if (count($value) === 2) {
                    $query->where($column, '=', $this->scalarOrNull($value[1]));

                    continue;
                }

                $query->where($column, $this->scalarOrNull($value[1]), $this->scalarOrNull($value[2]));
            }
        }, $boolean);
    }

    private function scalarOrNull(mixed $value): null|bool|float|int|string
    {
        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException(
                sprintf('A where value must be scalar or null, %s given.', get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * @template TValue
     *
     * @param  TValue  $operator
     * @param  TValue  $value
     * @return array{string, TValue}
     */
    private function resolveOperator(mixed $operator, mixed $value, bool $valueOnly): array
    {
        if ($valueOnly) {
            return ['=', $operator];
        }

        if ($value === null && ! in_array($operator, self::OPERATORS, true)) {
            return ['=', $operator];
        }

        return [is_string($operator) ? strtolower($operator) : '=', $value];
    }

    /**
     * @param  array<array-key, mixed>|(callable(static): mixed)|string  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function orWhere(array|callable|string $column, mixed $operator = null, mixed $value = null): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->where($column, $operator, $value, 'or');
    }

    private function whereGroup(callable $callback, string $boolean): static
    {
        $nested = new self($this->files, $this->driver, $this->cache, $this->contentPath, $this->modelClass);
        $callback($nested);

        $this->wheres[] = [
            'type' => 'group',
            'wheres' => $nested->wheres,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  array<int, scalar>  $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  array<int, scalar>  $values
     */
    public function orWhereIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * @param  array<int, scalar>  $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'notIn',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  array<int, scalar>  $values
     */
    public function orWhereNotIn(string $column, array $values): static
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * Matches rows where the array field includes the given value.
     *
     * @param  scalar  $value
     */
    public function whereContains(string $column, mixed $value, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'contains',
            'column' => $column,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  scalar  $value
     */
    public function orWhereContains(string $column, mixed $value): static
    {
        return $this->whereContains($column, $value, 'or');
    }

    public function whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => $value,
            'caseSensitive' => $caseSensitive,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'or');
    }

    public function whereNotLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'notLike',
            'column' => $column,
            'value' => $value,
            'caseSensitive' => $caseSensitive,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereNotLike($column, $value, $caseSensitive, 'or');
    }

    public function whereRegexp(string $column, string $pattern, string $boolean = 'and'): static
    {
        return $this->addRegexpWhere('regexp', $column, $pattern, $boolean);
    }

    public function orWhereRegexp(string $column, string $pattern): static
    {
        return $this->whereRegexp($column, $pattern, 'or');
    }

    public function whereNotRegexp(string $column, string $pattern, string $boolean = 'and'): static
    {
        return $this->addRegexpWhere('notRegexp', $column, $pattern, $boolean);
    }

    public function orWhereNotRegexp(string $column, string $pattern): static
    {
        return $this->whereNotRegexp($column, $pattern, 'or');
    }

    private function addRegexpWhere(string $type, string $column, string $pattern, string $boolean): static
    {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException(sprintf('Invalid regular expression: %s', $pattern));
        }

        $this->wheres[] = [
            'type' => $type,
            'column' => $column,
            'value' => $pattern,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereColumn(string $first, string $operator, ?string $second = null, string $boolean = 'and'): static
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        if ($this->transformsOnHydration($first) !== $this->transformsOnHydration($second)) {
            throw new InvalidArgumentException(sprintf(
                "whereColumn('%s', '%s'): columns must have the same cast status; one is transformed on hydration and the other is not.",
                $first,
                $second,
            ));
        }

        $this->wheres[] = [
            'type' => 'column',
            'column' => $first,
            'second' => $second,
            'operator' => strtolower($operator),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereColumn(string $first, string $operator, ?string $second = null): static
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    private function transformsOnHydration(string $column): bool
    {
        $model = $this->model();

        return in_array($column, $model->getDates(), true)
            || $model->hasCast($column)
            || $model->hasGetMutator($column)
            || $model->hasAttributeGetMutator($column);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->where(function (self $query) use ($columns, $operator, $value): void {
            foreach ($columns as $column) {
                $query->orWhere($column, $operator, $value);
            }
        }, boolean: $boolean);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function orWhereAny(array $columns, mixed $operator = null, mixed $value = null): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->whereAny($columns, $operator, $value, 'or');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->where(function (self $query) use ($columns, $operator, $value): void {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value);
            }
        }, boolean: $boolean);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function orWhereAll(array $columns, mixed $operator = null, mixed $value = null): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->whereAll($columns, $operator, $value, 'or');
    }

    public function whereNull(string $column, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNull(string $column): static
    {
        return $this->whereNull($column, 'or');
    }

    public function whereNotNull(string $column, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'notNull',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNotNull(string $column): static
    {
        return $this->whereNotNull($column, 'or');
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public function orWhereBetween(string $column, array $values): static
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'notBetween',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public function orWhereNotBetween(string $column, array $values): static
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    public function whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('date', $column, $operator, $value, $boolean);
    }

    public function orWhereDate(string $column, mixed $operator, mixed $value = null): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('date', $column, $operator, $value, 'or');
    }

    public function whereYear(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('year', $column, $operator, $value, $boolean);
    }

    public function orWhereYear(string $column, mixed $operator, mixed $value = null): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('year', $column, $operator, $value, 'or');
    }

    public function whereMonth(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('month', $column, $operator, $value, $boolean);
    }

    public function orWhereMonth(string $column, mixed $operator, mixed $value = null): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('month', $column, $operator, $value, 'or');
    }

    public function whereDay(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('day', $column, $operator, $value, $boolean);
    }

    public function orWhereDay(string $column, mixed $operator, mixed $value = null): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->addDateWhere('day', $column, $operator, $value, 'or');
    }

    private function addDateWhere(string $type, string $column, string $operator, mixed $value, string $boolean): static
    {
        if ($value instanceof DateTimeInterface) {
            $carbon = Carbon::instance($value);
            $value = match ($type) {
                'date' => $carbon->format('Y-m-d'),
                'year' => $carbon->year,
                'month' => $carbon->month,
                default => $carbon->day,
            };
        }

        $this->wheres[] = [
            'type' => $type,
            'column' => $column,
            'operator' => $operator,
            'value' => $this->scalarOrNull($value),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction),
        ];

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function latest(?string $column = null): static
    {
        return $this->orderBy($column ?? $this->createdAtColumn(), 'desc');
    }

    public function oldest(?string $column = null): static
    {
        return $this->orderBy($column ?? $this->createdAtColumn());
    }

    private function createdAtColumn(): string
    {
        return $this->model()->getCreatedAtColumn() ?? 'created_at';
    }

    public function inRandomOrder(): static
    {
        $this->randomOrder = true;

        return $this;
    }

    public function limit(int $value): static
    {
        if ($value >= 0) {
            $this->limitValue = $value;
        }

        return $this;
    }

    public function take(int $value): static
    {
        return $this->limit($value);
    }

    public function offset(int $value): static
    {
        $this->offsetValue = $value;

        return $this;
    }

    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    /**
     * @return ?TModel
     */
    public function first(): ?Model
    {
        if ($this->orders === []) {
            return $this->lazy()->first();
        }

        return $this->limit(1)->get()->first();
    }

    /**
     * @param  array<array-key, mixed>|(callable(static): mixed)|string  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return ?TModel
     */
    public function firstWhere(array|callable|string $column, mixed $operator = null, mixed $value = null): ?Model
    {
        [$operator, $value] = $this->resolveOperator($operator, $value, func_num_args() === 2);

        return $this->where($column, $operator, $value)->first();
    }

    public function value(string $column): mixed
    {
        return $this->first()?->getAttribute($column);
    }

    /**
     * @return TModel
     */
    public function firstOrFail(): Model
    {
        $model = $this->first();

        if ($model === null) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $this->modelClass;

            throw (new ModelNotFoundException)->setModel($modelClass);
        }

        return $model;
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TModel|TValue
     */
    public function firstOr(Closure $callback): mixed
    {
        return $this->first() ?? $callback();
    }

    /**
     * @return TModel
     */
    public function sole(): Model
    {
        $items = $this->lazy()->take(2)->all();

        if ($items === []) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $this->modelClass;

            throw (new ModelNotFoundException)->setModel($modelClass);
        }

        if (isset($items[1])) {
            throw new MultipleRecordsFoundException(2);
        }

        return $items[0];
    }

    public function count(): int
    {
        if ($this->wheres === []) {
            return $this->scanFiles()->count();
        }

        return $this->lazyModels()->count();
    }

    public function exists(): bool
    {
        if ($this->wheres === []) {
            return $this->scanFiles()->isNotEmpty();
        }

        return $this->lazyModels()->isNotEmpty();
    }

    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    public function min(string $column): mixed
    {
        return collect($this->columnValues($column))->min();
    }

    public function max(string $column): mixed
    {
        return collect($this->columnValues($column))->max();
    }

    public function sum(string $column): float|int
    {
        $total = 0;

        foreach ($this->columnValues($column) as $value) {
            if (is_numeric($value)) {
                $total += $value;
            }
        }

        return $total;
    }

    public function avg(string $column): null|float|int
    {
        $numeric = array_filter($this->columnValues($column), is_numeric(...));

        return collect($numeric)->avg();
    }

    public function average(string $column): null|float|int
    {
        return $this->avg($column);
    }

    /**
     * @return Collection<array-key, int>
     */
    public function countBy(string $column): Collection
    {
        $values = collect($this->columnValues($column))->flatten(1);
        $scalars = $values->reject(fn (mixed $value): bool => ! is_scalar($value));

        /**
         * @var Collection<array-key, int>
         */
        return $scalars->countBy();
    }

    public function delete(): int
    {
        $deleted = 0;

        foreach ($this->getModels() as $model) {
            if ($model->delete()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Saves each matching record in turn, so a mid-loop failure leaves earlier writes applied.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $updated = 0;

        foreach ($this->getModels() as $model) {
            $model->forceFill($values);

            if ($model->save()) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @return Collection<int, mixed>
     */
    public function pluck(string $column, ?string $key = null): Collection
    {
        return $this->getModels()->pluck($column, $key);
    }

    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $page = $page ?: Paginator::resolveCurrentPage();
        $perPage = $perPage ?: $this->model()->getPerPage();
        $offset = max(0, ($page - 1) * $perPage);

        if ($this->wheres === [] && $this->ordersAreParseFree()) {
            $files = $this->orderedFiles();
            $total = $files->count();

            $items = $files->slice($offset)
                ->take($perPage)
                ->map(fn (string $filepath): Model => $this->fileToModel($filepath))
                ->values();

            $items->each($this->fireRetrieved(...));

            return new LengthAwarePaginator($items, $total, $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
            ]);
        }

        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        try {
            $this->limitValue = null;
            $this->offsetValue = 0;

            $all = $this->getModels();
            $total = $all->count();
            $items = $all->slice($offset)->take($perPage)->values();

            $items->each($this->fireRetrieved(...));

            return new LengthAwarePaginator($items, $total, $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
            ]);
        } finally {
            $this->limitValue = $originalLimit;
            $this->offsetValue = $originalOffset;
        }
    }

    /**
     * @return Paginator<int, TModel>
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null): Paginator
    {
        $page = $page ?: Paginator::resolveCurrentPage();
        $perPage = $perPage ?: $this->model()->getPerPage();
        $offset = max(0, ($page - 1) * $perPage);

        if ($this->wheres === [] && $this->ordersAreParseFree()) {
            $items = $this->orderedFiles()
                ->slice($offset)
                ->take($perPage + 1)
                ->map(fn (string $filepath): Model => $this->fileToModel($filepath))
                ->values();

            $items->each($this->fireRetrieved(...));

            return new Paginator($items, $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
            ]);
        }

        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        try {
            $this->limitValue = null;
            $this->offsetValue = 0;

            $items = $this->lazyModels()->skip($offset)->take($perPage + 1)->collect();

            $items->each($this->fireRetrieved(...));

            return new Paginator($items, $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
            ]);
        } finally {
            $this->limitValue = $originalLimit;
            $this->offsetValue = $originalOffset;
        }
    }

    /**
     * @return Collection<int, TModel>
     */
    public function get(): Collection
    {
        $models = $this->getModels();

        $models->each($this->fireRetrieved(...));

        return $models;
    }

    /**
     * @return Collection<int, TModel>
     */
    private function getModels(): Collection
    {
        $models = $this->scanFiles()
            ->map(fn (string $filepath): Model => $this->fileToModel($filepath))
            ->filter(fn (Model $model): bool => $this->matchesWheres($model));

        $results = $this->applyOrdersAndLimits($models);

        return $this->model()->newCollection($results->all());
    }

    /**
     * Ignores orders, limits and offsets so aggregates span every matching record.
     *
     * @return list<mixed>
     */
    private function columnValues(string $column): array
    {
        $values = [];

        foreach ($this->scanFiles() as $filepath) {
            $model = $this->fileToModel($filepath);

            if ($this->matchesWheres($model)) {
                $values[] = $model->getAttribute($column);
            }
        }

        return $values;
    }

    /**
     * Parses files lazily, but lists them all up front.
     *
     * @return LazyCollection<int, TModel>
     */
    public function lazy(): LazyCollection
    {
        return new LazyCollection(function (): Generator {
            foreach ($this->yieldModels() as $model) {
                $this->fireRetrieved($model);

                yield $model;
            }
        });
    }

    /**
     * @param  callable(Collection<int, TModel>, int): mixed  $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;

        foreach ($this->lazy()->chunk($count) as $chunk) {
            $models = $this->model()->newCollection($chunk->all());

            if ($callback($models, $page) === false) {
                return false;
            }

            $page++;
        }

        return true;
    }

    /**
     * @param  callable(TModel, array-key): mixed  $callback
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function (Collection $models) use ($callback): bool {
            foreach ($models as $key => $model) {
                if ($callback($model, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @return LazyCollection<int, TModel>
     */
    private function lazyModels(): LazyCollection
    {
        return new LazyCollection($this->yieldModels(...));
    }

    /**
     * @param  (callable(static, mixed): mixed)|null  $callback
     * @param  (callable(static, mixed): mixed)|null  $default
     */
    public function when(mixed $value, ?callable $callback = null, ?callable $default = null): static
    {
        $active = $value ? $callback : $default;

        if ($active !== null) {
            $active($this, $value);
        }

        return $this;
    }

    /**
     * @param  (callable(static, mixed): mixed)|null  $callback
     * @param  (callable(static, mixed): mixed)|null  $default
     */
    public function unless(mixed $value, ?callable $callback = null, ?callable $default = null): static
    {
        $active = $value ? $default : $callback;

        if ($active !== null) {
            $active($this, $value);
        }

        return $this;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): static
    {
        $scope = $this->resolveScope($method);

        if ($scope === null) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', self::class, $method)
            );
        }

        $scope->invoke($this->model(), $this, ...$parameters);

        return $this;
    }

    private function resolveScope(string $method): ?ReflectionMethod
    {
        $prefixed = 'scope'.ucfirst($method);

        if (method_exists($this->modelClass, $prefixed)) {
            return new ReflectionMethod($this->modelClass, $prefixed);
        }

        if (! method_exists($this->modelClass, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($this->modelClass, $method);

        if ($reflection->getAttributes(ScopeAttribute::class) === []) {
            return null;
        }

        return $reflection;
    }

    /**
     * @return Generator<int, TModel, mixed, void>
     */
    private function yieldModels(): Generator
    {
        $files = $this->scanFiles();

        if ($this->orders !== [] || $this->randomOrder) {
            yield from $this->yieldOrdered($files);

            return;
        }

        yield from $this->yieldUnordered($files);
    }

    /**
     * @param  Collection<int, string>  $files
     * @return Generator<int, TModel>
     */
    private function yieldOrdered(Collection $files): Generator
    {
        $models = $files
            ->map(fn (string $filepath): Model => $this->fileToModel($filepath))
            ->filter(fn (Model $model): bool => $this->matchesWheres($model));

        foreach ($this->applyOrdersAndLimits($models) as $model) {
            yield $model;
        }
    }

    /**
     * @param  Collection<int, TModel>  $models
     * @return Collection<int, TModel>
     */
    private function applyOrdersAndLimits(Collection $models): Collection
    {
        foreach (array_reverse($this->orders) as $order) {
            $models = $models->sortBy(
                fn (Model $model): mixed => $model->getAttribute($order['column']),
                SORT_REGULAR,
                $order['direction'] === 'desc'
            );
        }

        if ($this->randomOrder) {
            $models = $models->shuffle();
        }

        if ($this->offsetValue > 0) {
            $models = $models->slice($this->offsetValue);
        }

        if ($this->limitValue !== null) {
            $models = $models->take($this->limitValue);
        }

        return $models->values();
    }

    private function updatedAtColumn(): ?string
    {
        $model = $this->model();

        return $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;
    }

    /**
     * Only the slug is readable from the filename; a timestamp column can come from the file itself.
     */
    private function ordersAreParseFree(): bool
    {
        if ($this->randomOrder) {
            return false;
        }

        return array_all($this->orders, fn (array $order): bool => $order['column'] === 'slug');
    }

    /**
     * @return Collection<int, string>
     */
    private function orderedFiles(): Collection
    {
        $files = $this->scanFiles();

        if ($this->orders === []) {
            return $files;
        }

        foreach (array_reverse($this->orders) as $order) {
            $files = $files->sortBy(
                static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
                SORT_REGULAR,
                $order['direction'] === 'desc'
            );
        }

        return $files->values();
    }

    /**
     * @param  Collection<int, string>  $files
     * @return Generator<int, TModel>
     */
    private function yieldUnordered(Collection $files): Generator
    {
        $yielded = 0;
        $skipped = 0;

        foreach ($files as $filepath) {
            $model = $this->fileToModel($filepath);

            if (! $this->matchesWheres($model)) {
                continue;
            }

            if ($skipped < $this->offsetValue) {
                $skipped++;

                continue;
            }

            if ($this->limitValue !== null && $yielded >= $this->limitValue) {
                return;
            }

            yield $model;
            $yielded++;
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function scanFiles(): Collection
    {
        if (! $this->files->isDirectory($this->contentPath)) {
            throw ContentPathNotFoundException::forPath($this->contentPath, $this->modelClass);
        }

        $entries = scandir($this->contentPath, SCANDIR_SORT_NONE) ?: [];
        $matches = [];

        // Extensions stay outermost so the earliest one wins a slug, matching locate().
        // Fold it into one pass and the filesystem picks instead.
        foreach ($this->driver->extensions() as $extension) {
            $suffix = '.'.$extension;

            foreach ($entries as $entry) {
                if ($entry[0] === '.' || ! str_ends_with($entry, $suffix)) {
                    continue;
                }

                $slug = substr($entry, 0, -strlen($suffix));

                if (! isset($matches[$slug])) {
                    $matches[$slug] = $this->contentPath.'/'.$entry;
                }
            }
        }

        ksort($matches, SORT_STRING);

        /** @var Collection<int, string> */
        return collect(array_values($matches));
    }

    /**
     * Fires the retrieved event via a bound closure, matching Eloquent's newFromBuilder approach.
     */
    private function fireRetrieved(Model $model): void
    {
        (function (): void {
            $this->fireModelEvent('retrieved', false);
        })->call($model);
    }

    /**
     * @return TModel
     */
    private function fileToModel(string $filepath): Model
    {
        // Stats every file, because a directory's mtime does not move when its files are edited.
        $mtime = @filemtime($filepath);
        $data = $this->loadFileData($filepath, is_int($mtime) ? $mtime : 0);
        $slug = pathinfo($filepath, PATHINFO_FILENAME);

        $data['slug'] = $slug;

        $column = $this->updatedAtColumn();

        if ($column !== null && is_int($mtime) && ! array_key_exists($column, $data)) {
            $data[$column] = $mtime;
        }

        $model = new $this->modelClass;
        $attributes = PaperCasts::fromStorage($model, $data);
        $model->setRawAttributes($attributes, true);
        $model->exists = true;

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFileData(string $filepath, int $mtime): array
    {
        $cached = $this->cache->getIfFresh($filepath, $mtime);

        if ($cached !== null) {
            return $cached;
        }

        $data = $this->driver->parse($filepath);
        $this->cache->set($filepath, $data, $mtime);

        return $data;
    }

    /**
     * @param  ?array<int, array{type: string, boolean: string, column?: string, second?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool}>  $wheres
     */
    private function matchesWheres(Model $model, ?array $wheres = null): bool
    {
        $wheres ??= $this->wheres;

        if ($wheres === []) {
            return true;
        }

        $result = false;
        $group = true;

        foreach ($wheres as $index => $where) {
            /** @var array{type: string, boolean: string, column?: string, second?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>} $where */
            if ($index > 0 && $where['boolean'] === 'or') {
                $result = $result || $group;
                $group = true;
            }

            $group = $group && $this->evaluateWhere($model, $where);
        }

        return $result || $group;
    }

    /**
     * @param  array{type: string, boolean: string, column?: string, second?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool, wheres?: array<int, array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>}>}  $where
     */
    private function evaluateWhere(Model $model, array $where): bool
    {
        if ($where['type'] === 'group') {
            $nested = $where['wheres'] ?? [];

            return $this->matchesWheres($model, $nested);
        }

        $column = $where['column'] ?? '';
        $value = $model->getAttribute($column);

        return match ($where['type']) {
            'in' => $value !== null && in_array($value, $where['values'] ?? []),
            'notIn' => $value !== null && ! in_array($value, $where['values'] ?? []),
            'contains' => is_array($value) && in_array($where['value'] ?? null, $value, true),
            'like' => is_string($value) && $this->evaluateLike($value, (string) ($where['value'] ?? ''), $where['caseSensitive'] ?? false),
            'notLike' => is_string($value) && ! $this->evaluateLike($value, (string) ($where['value'] ?? ''), $where['caseSensitive'] ?? false),
            'regexp' => is_string($value) && preg_match((string) ($where['value'] ?? ''), $value) === 1,
            'notRegexp' => is_string($value) && preg_match((string) ($where['value'] ?? ''), $value) !== 1,
            'null' => $value === null,
            'notNull' => $value !== null,
            'between' => $value !== null && $this->evaluateBetween($value, $where['values'] ?? []),
            'notBetween' => $value !== null && ! $this->evaluateBetween($value, $where['values'] ?? []),
            'column' => $this->evaluateCondition($value, $where['operator'] ?? '=', $model->getAttribute($where['second'] ?? '')),
            'date', 'year', 'month', 'day' => $this->evaluateDate($value, $where['type'], $where['operator'] ?? '=', $where['value'] ?? null),
            default => $this->evaluateCondition($value, $where['operator'] ?? '=', $where['value'] ?? null),
        };
    }

    private function evaluateDate(mixed $value, string $part, string $operator, mixed $expected): bool
    {
        $date = $this->toDate($value);

        if ($date === null) {
            return false;
        }

        if ($part === 'date') {
            $bound = $this->toDate($expected);

            return $bound !== null && $this->evaluateCondition($date->format('Y-m-d'), $operator, $bound->format('Y-m-d'));
        }

        $actual = match ($part) {
            'year' => $date->year,
            'month' => $date->month,
            default => $date->day,
        };

        return $this->evaluateCondition($actual, $operator, $expected);
    }

    private function toDate(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value, 'UTC');
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value, 'UTC');
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function evaluateCondition(mixed $actual, string $operator, mixed $expected): bool
    {
        $bothPresent = $actual !== null && $expected !== null;

        return match ($operator) {
            '=' => $bothPresent && $actual == $expected,
            '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=', '<>' => $bothPresent && $actual != $expected,
            '!==' => $actual !== $expected,
            '>' => $bothPresent && $actual > $expected,
            '>=' => $bothPresent && $actual >= $expected,
            '<' => $bothPresent && $actual < $expected,
            '<=' => $bothPresent && $actual <= $expected,
            'like' => is_string($actual) && is_string($expected) && $this->evaluateLike($actual, $expected),
            default => false,
        };
    }

    private function evaluateLike(string $actual, string $pattern, bool $caseSensitive = false): bool
    {
        $modifiers = $caseSensitive ? '' : 'i';
        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')).'$/'.$modifiers;

        return (bool) preg_match($regex, $actual);
    }

    /**
     * @param  array<int, scalar>  $values
     */
    private function evaluateBetween(mixed $value, array $values): bool
    {
        $bounds = array_values($values);

        if (count($bounds) < 2) {
            return false;
        }

        return $value >= $bounds[0] && $value <= $bounds[1];
    }
}
