<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Contracts\ConditionalWriteContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Contracts\ScopeContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\DuplicateSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidCollectionException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\StaleRecordException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedConcurrencyException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedDatabaseQueryException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedScopeException;
use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;
use JacobJoergensen\LaravelPaper\Relations\PaperRelation;
use JacobJoergensen\LaravelPaper\StorageAdapters\BestEffortWriter;
use JacobJoergensen\LaravelPaper\StorageAdapters\ConditionalWriteStatus;
use JacobJoergensen\LaravelPaper\StorageAdapters\UncheckedWriter;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @mixin Model
 *
 * @phpstan-require-implements PaperModel
 */
trait Paper
{
    private ?string $paperExtension = null;

    private ?string $paperVersion = null;

    public static function resetPaperState(): void
    {
        PaperQueryBuilder::forgetCache(static::class);
    }

    /**
     * @param  mixed  $scope
     * @param  mixed  $implementation
     */
    public static function addGlobalScope($scope, $implementation = null): void
    {
        $resolved = self::resolveGlobalScope($implementation ?? $scope);

        $identifier = match (true) {
            is_string($scope) => $scope,
            $resolved instanceof Closure => spl_object_hash($resolved),
            default => $resolved::class,
        };

        /** @var array<class-string, array<string, Closure|ScopeContract<static>>> $scopes */
        $scopes = static::getAllGlobalScopes();
        $scopes[static::class][$identifier] = $resolved;

        static::setAllGlobalScopes($scopes);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function query(): PaperQueryBuilder
    {
        return PaperQueryBuilder::forModel(static::class);
    }

    /**
     * @param  ScopeContract<static>|string  $scope
     * @return PaperQueryBuilder<static>
     */
    public static function withoutGlobalScope(ScopeContract|string $scope): PaperQueryBuilder
    {
        return static::query()->withoutGlobalScope($scope);
    }

    /**
     * @param  ?array<int, ScopeContract<static>|string>  $scopes
     * @return PaperQueryBuilder<static>
     */
    public static function withoutGlobalScopes(?array $scopes = null): PaperQueryBuilder
    {
        return static::query()->withoutGlobalScopes($scopes);
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     * @return Collection<int, static>
     */
    public static function all($columns = ['*']): Collection
    {
        return static::query()->get();
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     */
    public static function find(mixed $id, $columns = ['*']): ?static
    {
        return static::query()->find(self::keyToString($id));
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     */
    public static function findOrFail(mixed $id, $columns = ['*']): static
    {
        $model = static::find($id, $columns);

        if ($model === null) {
            throw new ModelNotFoundException()->setModel(static::class, [self::keyToString($id)]);
        }

        return $model;
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return static|TValue
     */
    public static function findOr(mixed $id, Closure $callback): mixed
    {
        return static::query()->findOr(self::keyToString($id), $callback);
    }

    /**
     * @param  array<int, scalar>  $ids
     * @return Collection<int, static>
     */
    public static function findMany(array $ids): Collection
    {
        return static::query()->findMany($ids);
    }

    /**
     * @param  (Closure(PaperQueryBuilder<static>): mixed)|string|array<array-key, mixed>  $column
     * @return PaperQueryBuilder<static>
     */
    public static function where(array|Closure|string $column, null|bool|float|int|string $operator = null, null|bool|float|int|string $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->where($column, $operator, $value);
    }

    /**
     * @param  (Closure(PaperQueryBuilder<static>): mixed)|string|array<array-key, mixed>  $column
     * @return PaperQueryBuilder<static>
     */
    public static function orWhere(array|Closure|string $column, null|bool|float|int|string $operator = null, null|bool|float|int|string $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->orWhere($column, $operator, $value);
    }

    /**
     * @param  (callable(PaperQueryBuilder<static>, mixed): mixed)|null  $callback
     * @param  (callable(PaperQueryBuilder<static>, mixed): mixed)|null  $default
     * @return PaperQueryBuilder<static>
     */
    public static function when(mixed $value, ?callable $callback = null, ?callable $default = null): PaperQueryBuilder
    {
        return static::query()->when($value, $callback, $default);
    }

    /**
     * @param  (callable(PaperQueryBuilder<static>, mixed): mixed)|null  $callback
     * @param  (callable(PaperQueryBuilder<static>, mixed): mixed)|null  $default
     * @return PaperQueryBuilder<static>
     */
    public static function unless(mixed $value, ?callable $callback = null, ?callable $default = null): PaperQueryBuilder
    {
        return static::query()->unless($value, $callback, $default);
    }

    /**
     * @param  array<int, scalar>  $values
     * @return PaperQueryBuilder<static>
     */
    public static function whereIn(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereIn($column, $values);
    }

    /**
     * @param  array<int, scalar>  $values
     * @return PaperQueryBuilder<static>
     */
    public static function whereNotIn(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereNotIn($column, $values);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereContains(string $column, bool|float|int|string $value): PaperQueryBuilder
    {
        return static::query()->whereContains($column, $value);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereLike(string $column, string $value, bool $caseSensitive = false): PaperQueryBuilder
    {
        return static::query()->whereLike($column, $value, $caseSensitive);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereLike(string $column, string $value, bool $caseSensitive = false): PaperQueryBuilder
    {
        return static::query()->orWhereLike($column, $value, $caseSensitive);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereNotLike(string $column, string $value, bool $caseSensitive = false): PaperQueryBuilder
    {
        return static::query()->whereNotLike($column, $value, $caseSensitive);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): PaperQueryBuilder
    {
        return static::query()->orWhereNotLike($column, $value, $caseSensitive);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereColumn(string $first, string $operator, ?string $second = null): PaperQueryBuilder
    {
        return static::query()->whereColumn($first, $operator, $second);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereColumn(string $first, string $operator, ?string $second = null): PaperQueryBuilder
    {
        return static::query()->orWhereColumn($first, $operator, $second);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereRegexp(string $column, string $pattern): PaperQueryBuilder
    {
        return static::query()->whereRegexp($column, $pattern);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereRegexp(string $column, string $pattern): PaperQueryBuilder
    {
        return static::query()->orWhereRegexp($column, $pattern);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereNotRegexp(string $column, string $pattern): PaperQueryBuilder
    {
        return static::query()->whereNotRegexp($column, $pattern);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereNotRegexp(string $column, string $pattern): PaperQueryBuilder
    {
        return static::query()->orWhereNotRegexp($column, $pattern);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereDate(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->whereDate($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereDate(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->orWhereDate($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereYear(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->whereYear($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereYear(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->orWhereYear($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereMonth(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->whereMonth($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereMonth(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->orWhereMonth($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereDay(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->whereDay($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereDay(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->orWhereDay($column, $operator, $value);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function has(string $relation, string $operator = '>=', int $count = 1, ?Closure $constraint = null): PaperQueryBuilder
    {
        return static::query()->has($relation, $operator, $count, 'and', $constraint);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orHas(string $relation, string $operator = '>=', int $count = 1): PaperQueryBuilder
    {
        return static::query()->orHas($relation, $operator, $count);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function doesntHave(string $relation, ?Closure $constraint = null): PaperQueryBuilder
    {
        return static::query()->doesntHave($relation, 'and', $constraint);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orDoesntHave(string $relation): PaperQueryBuilder
    {
        return static::query()->orDoesntHave($relation);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereHas(string $relation, ?Closure $constraint = null, string $operator = '>=', int $count = 1): PaperQueryBuilder
    {
        return static::query()->whereHas($relation, $constraint, $operator, $count);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereHas(string $relation, ?Closure $constraint = null, string $operator = '>=', int $count = 1): PaperQueryBuilder
    {
        return static::query()->orWhereHas($relation, $constraint, $operator, $count);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereDoesntHave(string $relation, ?Closure $constraint = null): PaperQueryBuilder
    {
        return static::query()->whereDoesntHave($relation, $constraint);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereDoesntHave(string $relation, ?Closure $constraint = null): PaperQueryBuilder
    {
        return static::query()->orWhereDoesntHave($relation, $constraint);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 3 ? ['=', $operator] : [$operator, $value];

        return static::query()->whereRelation($relation, $column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 3 ? ['=', $operator] : [$operator, $value];

        return static::query()->orWhereRelation($relation, $column, $operator, $value);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereAny(array $columns, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->whereAny($columns, $operator, $value);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereAll(array $columns, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->whereAll($columns, $operator, $value);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereNull(string $column): PaperQueryBuilder
    {
        return static::query()->whereNull($column);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function whereNotNull(string $column): PaperQueryBuilder
    {
        return static::query()->whereNotNull($column);
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     * @return PaperQueryBuilder<static>
     */
    public static function whereBetween(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereBetween($column, $values);
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     * @return PaperQueryBuilder<static>
     */
    public static function whereNotBetween(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereNotBetween($column, $values);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orderBy(string $column, string $direction = 'asc'): PaperQueryBuilder
    {
        return static::query()->orderBy($column, $direction);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function orderByDesc(string $column): PaperQueryBuilder
    {
        return static::query()->orderByDesc($column);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function latest(?string $column = null): PaperQueryBuilder
    {
        return static::query()->latest($column);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function oldest(?string $column = null): PaperQueryBuilder
    {
        return static::query()->oldest($column);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function inRandomOrder(): PaperQueryBuilder
    {
        return static::query()->inRandomOrder();
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function limit(int $value): PaperQueryBuilder
    {
        return static::query()->limit($value);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function take(int $value): PaperQueryBuilder
    {
        return static::query()->take($value);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function offset(int $value): PaperQueryBuilder
    {
        return static::query()->offset($value);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function skip(int $value): PaperQueryBuilder
    {
        return static::query()->skip($value);
    }

    /**
     * @return Collection<int, static>
     */
    public static function get(): Collection
    {
        return static::query()->get();
    }

    public static function first(): ?static
    {
        return static::query()->first();
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public static function firstWhere(string $column, mixed $operator = null, mixed $value = null): ?static
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->firstWhere($column, $operator, $value);
    }

    public static function firstOrFail(): static
    {
        return static::query()->firstOrFail();
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return static|TValue
     */
    public static function firstOr(Closure $callback): mixed
    {
        return static::query()->firstOr($callback);
    }

    public static function sole(): static
    {
        return static::query()->sole();
    }

    public static function count(): int
    {
        return static::query()->count();
    }

    public static function min(string $column): mixed
    {
        return static::query()->min($column);
    }

    public static function max(string $column): mixed
    {
        return static::query()->max($column);
    }

    public static function sum(string $column): float|int
    {
        return static::query()->sum($column);
    }

    public static function avg(string $column): null|float|int
    {
        return static::query()->avg($column);
    }

    public static function average(string $column): null|float|int
    {
        return static::query()->average($column);
    }

    /**
     * @return Collection<array-key, int>
     */
    public static function countBy(string $column): Collection
    {
        return static::query()->countBy($column);
    }

    public static function exists(): bool
    {
        return static::query()->exists();
    }

    public static function doesntExist(): bool
    {
        return static::query()->doesntExist();
    }

    /**
     * @return Collection<int, mixed>
     */
    public static function pluck(string $column, ?string $key = null): Collection
    {
        return static::query()->pluck($column, $key);
    }

    /**
     * @return LazyCollection<int, static>
     */
    public static function lazy(int $chunkSize = 1000): LazyCollection
    {
        return static::query()->lazy($chunkSize);
    }

    /**
     * @param  callable(Collection<int, static>, int): mixed  $callback
     */
    public static function chunk(int $count, callable $callback): bool
    {
        return static::query()->chunk($count, $callback);
    }

    /**
     * @param  callable(static, array-key): mixed  $callback
     */
    public static function each(callable $callback, int $count = 1000): bool
    {
        return static::query()->each($callback, $count);
    }

    public static function value(string $column): mixed
    {
        return static::query()->value($column);
    }

    /**
     * @return LengthAwarePaginator<int, static>
     */
    public static function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        return static::query()->paginate($perPage, $page);
    }

    /**
     * @return Paginator<int, static>
     */
    public static function simplePaginate(int $perPage = 15, ?int $page = null): Paginator
    {
        return static::query()->simplePaginate($perPage, $page);
    }

    /**
     * @param  array<int, static>  $models
     * @return PaperCollection<static>
     */
    public function newCollection(array $models = []): PaperCollection
    {
        $class = $this->resolveCollectionFromAttribute() ?? PaperCollection::class;
        $collection = new $class($models);

        if (! $collection instanceof PaperCollection) {
            throw InvalidCollectionException::forCollection($collection::class, static::class);
        }

        $autoloads = method_exists(Model::class, 'isAutomaticallyEagerLoadingRelationships')
            && Model::isAutomaticallyEagerLoadingRelationships();

        if ($autoloads) {
            $collection->withRelationshipAutoloading();
        }

        return $collection;
    }

    /**
     * @param  array<int|string, string|Closure>|string  $relations
     */
    public function load($relations, string ...$more): static
    {
        $names = is_string($relations) ? [$relations, ...$more] : $relations;

        static::query()->with($names)->eagerLoadRelations([$this]);

        return $this;
    }

    /**
     * @param  array<int|string, string|Closure>|string  $relations
     */
    public function loadMissing($relations, string ...$more): static
    {
        $names = is_string($relations) ? [$relations, ...$more] : $relations;
        $missing = [];

        foreach ($names as $key => $relation) {
            $name = is_int($key) ? $relation : $key;

            if (is_string($name) && ! $this->relationLoaded($name)) {
                $missing[$key] = $relation;
            }
        }

        return $missing === [] ? $this : $this->load($missing);
    }

    /**
     * @param  array<int|string, string|Closure>|string  $relations
     * @return PaperQueryBuilder<static>
     */
    public static function with($relations, string ...$more): PaperQueryBuilder
    {
        $names = is_string($relations) ? [$relations, ...$more] : $relations;

        return static::query()->with($names);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = []): static
    {
        $model = new static;
        $model->fill($attributes);

        $slug = self::keyToString($model->getAttribute($model->getKeyName()));

        if ($slug === '') {
            throw InvalidSlugException::missing();
        }

        $model->save();

        return $model;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $existing = self::firstWhereAttributes($attributes);

        if ($existing !== null) {
            return $existing;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function firstOrNew(array $attributes, array $values = []): static
    {
        $existing = self::firstWhereAttributes($attributes);

        if ($existing !== null) {
            return $existing;
        }

        $model = new static;
        $model->fill(array_merge($attributes, $values));

        return $model;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $existing = self::firstWhereAttributes($attributes);

        if ($existing !== null) {
            $existing->fill($values);
            $existing->save();

            return $existing;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * @param  array<int, scalar>|scalar  $ids
     */
    public static function destroy(mixed $ids): int
    {
        $keys = is_array($ids) ? $ids : [$ids];
        $key = new static()->getKeyName();

        return static::query()->whereIn($key, $keys)->delete();
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromRecord(array $attributes, string $version): static
    {
        $model = new static;
        $model->setRawAttributes(PaperCasts::fromStorage($model, $attributes), true);
        $model->exists = true;
        $model->paperVersion = $version;

        return $model;
    }

    public function getKeyName(): string
    {
        return 'slug';
    }

    public function getContentPath(): string
    {
        return PaperQueryBuilder::declaredContentPath(static::class);
    }

    public function getFilePath(): string
    {
        $slug = self::keyToString($this->getAttribute($this->getKeyName()));

        if ($slug === '') {
            throw InvalidSlugException::missing();
        }

        PaperQueryBuilder::guardSlug($slug);

        $resolved = PaperQueryBuilder::resolveFor(static::class);
        $driver = $resolved['driver'];
        $directory = PaperQueryBuilder::contentPathFor(static::class);

        if ($this->paperExtension === null) {
            $manifest = app(PaperManifest::class);

            $record = $this->exists
                ? $manifest->record($resolved['adapter'], $driver, $directory, $this->storedSlug(), $resolved['nested'])
                : null;

            // Held on the model so a renamed record keeps its format and a deleted one can still name its file.
            $this->paperExtension = $record['ext'] ?? $driver->extensions()[0];
        }

        return $directory.'/'.$slug.'.'.$this->paperExtension;
    }

    /**
     * @param  ?string  $field
     */
    public function resolveRouteBinding(mixed $value, $field = null): ?static
    {
        return static::query()->where($field ?? $this->getRouteKeyName(), self::keyToString($value))->first();
    }

    /**
     * @param  string  $childType
     * @param  ?string  $field
     */
    public function resolveChildRouteBinding($childType, mixed $value, mixed $field): ?Model
    {
        $relationName = Str::plural(Str::camel($childType));

        if (! method_exists($this, $relationName)) {
            throw new BadMethodCallException(
                sprintf('Relation %s::%s does not exist.', static::class, $relationName)
            );
        }

        $relation = $this->{$relationName}();

        if (! $relation instanceof PaperRelation) {
            throw new BadMethodCallException(
                sprintf(
                    'Relation %s::%s must return %s for scoped route binding.',
                    static::class,
                    $relationName,
                    PaperRelation::class,
                )
            );
        }

        $relatedClass = $relation->relatedClass;
        $childField = is_string($field) ? $field : new $relatedClass()->getRouteKeyName();

        return $relation->query()->where($childField, self::keyToString($value))->first();
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public function usesTimestamps(): bool
    {
        return PaperQueryBuilder::usesTimestamps(static::class);
    }

    /**
     * @param  string  $key
     */
    public function getAttribute($key): mixed
    {
        if (! array_key_exists($key, $this->attributes)) {
            $this->loadPaperBody($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * @param  string  $method
     */
    protected function getRelationshipFromMethod($method): mixed
    {
        $relation = $this->$method();

        if (! $relation instanceof PaperRelation) {
            return parent::getRelationshipFromMethod($method);
        }

        $results = $relation->getResults();

        $this->setRelation($method, $results);

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $this->loadPaperBody();

        return parent::attributesToArray();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $manifest = app(PaperManifest::class);

        $resolved = PaperQueryBuilder::resolveFor(static::class);
        $driver = $resolved['driver'];
        $path = PaperQueryBuilder::contentPathFor(static::class);
        $adapter = $resolved['adapter'];

        $isCreating = ! $this->exists;

        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if ($isCreating && $this->fireModelEvent('creating') === false) {
            return false;
        }

        if (! $isCreating && $this->fireModelEvent('updating') === false) {
            return false;
        }

        // Read after the events, because a listener may have set the slug or rewritten it.
        $slug = self::keyToString($this->getAttribute($this->getKeyName()));

        if ($slug === '') {
            return false;
        }

        PaperQueryBuilder::guardSlug($slug);

        if (! $resolved['nested'] && str_contains($slug, '/')) {
            throw InvalidSlugException::requiresNesting($slug);
        }

        $filepath = $this->getFilePath();
        $original = self::keyToString($this->getRawOriginal($this->getKeyName()));

        $this->loadPaperBody();

        $attributes = PaperCasts::toStorage($this, $this->getAttributes());

        if ($this->usesTimestamps()) {
            $updatedAt = $this->getUpdatedAtColumn();
            $stored = $manifest->record($adapter, $driver, $path, $this->storedSlug(), $resolved['nested']);

            if ($updatedAt !== null && ($stored === null || ! array_key_exists($updatedAt, $stored['data']))) {
                unset($attributes[$updatedAt]);
            }
        }

        $content = $driver->serialize($attributes);

        $adapter->ensureDirectoryExists(dirname($filepath));

        // A changed slug moves the record, like Eloquent updating a row by its original key.
        $isRenaming = $original !== '' && $original !== $slug;

        // Checked with the write, so the slug cannot be taken under another extension in between.
        $conflicts = self::siblingPaths($driver, $path, $slug, $filepath);

        $result = match (true) {
            $isCreating => self::writer($adapter)->createIfMissing($filepath, $content, $conflicts),
            $isRenaming => self::writer($adapter)->moveIf(
                $path.'/'.$original.'.'.$this->paperExtension,
                $filepath,
                $content,
                $this->writeVersion(),
                $conflicts,
            ),
            default => self::writer($adapter)->replaceIf($filepath, $content, $this->writeVersion()),
        };

        $loadedAs = $original === '' ? $slug : $original;

        match ($result->status) {
            ConditionalWriteStatus::Taken => throw DuplicateSlugException::forSlug($slug, (string) $result->path),
            ConditionalWriteStatus::Mismatch => throw StaleRecordException::changed($loadedAs),
            ConditionalWriteStatus::Missing => throw StaleRecordException::gone($loadedAs),
            default => null,
        };

        if ($result->status !== ConditionalWriteStatus::Written || $result->version === null) {
            return false;
        }

        $this->exists = true;
        $this->paperVersion = $result->version;

        $manifest->put($adapter, $driver, $path, $slug, $filepath, $driver->parse($content), $result->version, $resolved['nested']);

        if ($isRenaming) {
            $manifest->forget($adapter, $driver, $path, $original, $resolved['nested']);
        }

        if ($isCreating) {
            $this->wasRecentlyCreated = true;
        } else {
            $this->syncChanges();
        }

        $this->fireModelEvent($isCreating ? 'created' : 'updated', false);
        $this->fireModelEvent('saved', false);

        $this->syncOriginal();

        return true;
    }

    /**
     * The paths the same slug would take under the driver's other extensions.
     *
     * @return list<string>
     */
    private static function siblingPaths(DriverContract $driver, string $path, string $slug, string $filepath): array
    {
        $siblings = [];

        foreach ($driver->extensions() as $extension) {
            $candidate = $path.'/'.$slug.'.'.$extension;

            if ($candidate !== $filepath) {
                $siblings[] = $candidate;
            }
        }

        return $siblings;
    }

    /**
     * The token the record was loaded with, which the write is checked against.
     */
    private function writeVersion(): string
    {
        if (self::concurrency() === ConcurrencyPolicy::Off) {
            return '';
        }

        return $this->paperVersion ?? throw StaleRecordException::unverifiable(
            self::keyToString($this->getAttribute($this->getKeyName()))
        );
    }

    private static function concurrency(): ConcurrencyPolicy
    {
        return ConcurrencyPolicy::fromConfig(config('paper.concurrency'));
    }

    private static function writer(StorageAdapterContract $adapter): ConditionalWriteContract
    {
        $policy = self::concurrency();

        if ($policy === ConcurrencyPolicy::Off) {
            return new UncheckedWriter($adapter);
        }

        if ($adapter instanceof ConditionalWriteContract) {
            return $adapter;
        }

        if ($policy === ConcurrencyPolicy::Strict) {
            throw UnsupportedConcurrencyException::forAdapter($adapter::class);
        }

        return new BestEffortWriter($adapter);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function saveQuietly(array $options = []): bool
    {
        return $this->quietly(fn (): bool => $this->save($options));
    }

    /**
     * @param  ?array<int, string>  $except
     */
    public function replicate(?array $except = null): static
    {
        $this->loadPaperBody();

        return parent::replicate($except);
    }

    /**
     * @param  array<int|string, string|Closure>|string  $with
     */
    public function fresh($with = [], string ...$more): ?static
    {
        if (! $this->exists) {
            return null;
        }

        $names = is_string($with) ? [$with, ...$more] : $with;
        $key = self::keyToString($this->getAttribute($this->getKeyName()));

        return static::with($names)->find($key);
    }

    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $fresh = static::findOrFail($this->getAttribute($this->getKeyName()));
        $this->setRawAttributes($fresh->getAttributes(), true);
        $this->paperVersion = $fresh->paperVersion;

        $loaded = array_keys($this->relations);

        return $loaded === [] ? $this : $this->load($loaded);
    }

    /**
     * @return array<string, PaperRelation<Model&PaperModel>>
     */
    public function paperRelations(): array
    {
        $relations = [];

        foreach (new ReflectionClass(static::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            if (! is_a($returnType->getName(), PaperRelation::class, true)) {
                continue;
            }

            $name = $method->getName();
            $relation = $this->{$name}();

            if ($relation instanceof PaperRelation && $relation->parent === $this) {
                $relations[$name] = $relation;
            }
        }

        return $relations;
    }

    /**
     * @template TRelated of Model&PaperModel
     *
     * @param  class-string<TRelated>  $related
     * @return BelongsToPaper<TRelated>
     */
    protected function belongsToPaper(string $related, ?string $foreignKey = null): BelongsToPaper
    {
        $foreignKey ??= Str::snake(class_basename($related)).'_slug';

        return new BelongsToPaper($this, $related, $foreignKey);
    }

    /**
     * @template TRelated of Model&PaperModel
     *
     * @param  class-string<TRelated>  $related
     * @return HasManyPaper<TRelated>
     */
    protected function hasManyPaper(string $related, ?string $foreignKey = null): HasManyPaper
    {
        $foreignKey ??= Str::snake(class_basename(static::class)).'_slug';

        return new HasManyPaper($this, $related, $foreignKey);
    }

    public function delete(): bool
    {
        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $manifest = app(PaperManifest::class);

        $resolved = PaperQueryBuilder::resolveFor(static::class);
        $adapter = $resolved['adapter'];
        $path = PaperQueryBuilder::contentPathFor(static::class);
        $slug = self::keyToString($this->getAttribute($this->getKeyName()));
        $filepath = $this->getFilePath();
        $stored = self::keyToString($this->getRawOriginal($this->getKeyName()));

        // A record is deleted by the key it was loaded under, like Eloquent, so a slug changed
        // in a listener or left dirty on the model cannot point the delete at another record.
        if ($stored !== '' && $stored !== $slug) {
            $slug = $stored;
            $filepath = $path.'/'.$stored.'.'.$this->paperExtension;
        }

        $result = self::writer($adapter)->deleteIf($filepath, $this->writeVersion());

        match ($result->status) {
            ConditionalWriteStatus::Mismatch => throw StaleRecordException::changed($slug),
            ConditionalWriteStatus::Missing => throw StaleRecordException::gone($slug),
            default => null,
        };

        if ($result->status !== ConditionalWriteStatus::Removed) {
            return false;
        }

        $manifest->forget($adapter, $resolved['driver'], $path, $slug, $resolved['nested']);
        $this->exists = false;
        $this->paperVersion = null;
        $this->fireModelEvent('deleted', false);

        return true;
    }

    public function deleteQuietly(): bool
    {
        return $this->quietly(fn (): bool => $this->delete());
    }

    public function newQuery(): never
    {
        throw UnsupportedDatabaseQueryException::forModel(static::class);
    }

    public function newModelQuery(): never
    {
        throw UnsupportedDatabaseQueryException::forModel(static::class);
    }

    /**
     * @param  array<int, scalar>|scalar  $ids
     */
    public function newQueryForRestoration(mixed $ids): never
    {
        throw UnsupportedDatabaseQueryException::forModel(static::class);
    }

    /**
     * The one Eloquent builder Paper still hands out: a factory reads the connection name off it.
     *
     * @return Builder<static>
     */
    public function newQueryWithoutScopes(): Builder
    {
        return parent::newModelQuery();
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        // Eloquent would forward this to a database builder, and a Paper record has no table.
        return static::query()->{$method}(...$parameters);
    }

    /**
     * @param  callable(): bool  $callback
     */
    private function quietly(callable $callback): bool
    {
        $dispatcher = static::getEventDispatcher();

        if ($dispatcher !== null) {
            static::unsetEventDispatcher();
        }

        try {
            return $callback();
        } finally {
            if ($dispatcher !== null) {
                static::setEventDispatcher($dispatcher);
            }
        }
    }

    private function loadPaperBody(?string $key = null): void
    {
        $resolved = PaperQueryBuilder::resolveFor(static::class);
        $column = $resolved['driver']->bodyColumn();

        if ($column === null || ($key !== null && $key !== $column)) {
            return;
        }

        if (! $this->exists || array_key_exists($column, $this->attributes)) {
            return;
        }

        $path = PaperQueryBuilder::contentPathFor(static::class);
        $slug = $this->storedSlug();

        $body = app(PaperManifest::class)->body($resolved['adapter'], $resolved['driver'], $path, $slug, $resolved['nested']);
        $attributes = PaperCasts::fromStorage($this, [$column => $body]);

        $this->attributes[$column] = $attributes[$column];
        $this->original[$column] = $attributes[$column];
    }

    private function storedSlug(): string
    {
        $original = self::keyToString($this->getRawOriginal($this->getKeyName()));

        if ($original !== '') {
            return $original;
        }

        return self::keyToString($this->getAttribute($this->getKeyName()));
    }

    private static function keyToString(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * @return Closure|ScopeContract<static>
     */
    private static function resolveGlobalScope(mixed $scope): Closure|ScopeContract
    {
        if (is_string($scope) && is_subclass_of($scope, ScopeContract::class)) {
            return new $scope;
        }

        if ($scope instanceof Closure || $scope instanceof ScopeContract) {
            return $scope;
        }

        throw UnsupportedScopeException::forScope($scope);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function firstWhereAttributes(array $attributes): ?static
    {
        $query = static::query();

        foreach ($attributes as $column => $value) {
            /** @var ?scalar $value */
            $query->where($column, $value);
        }

        return $query->first();
    }
}
