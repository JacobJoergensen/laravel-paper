<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Contracts\ScopeContract;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedScopeException;
use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;
use ReflectionClass;

/**
 * @mixin Model
 *
 * @phpstan-require-implements PaperModel
 */
trait Paper
{
    private ?string $paperExtension = null;

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
        $resolved = static::resolveGlobalScope($implementation ?? $scope);

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
        return static::query()->find(static::keyToString($id));
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     */
    public static function findOrFail(mixed $id, $columns = ['*']): static
    {
        $model = static::find($id, $columns);

        if ($model === null) {
            throw new ModelNotFoundException()->setModel(static::class, [static::keyToString($id)]);
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
        return static::query()->findOr(static::keyToString($id), $callback);
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
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function where(array|Closure|string $column, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * @param  (Closure(PaperQueryBuilder<static>): mixed)|string|array<array-key, mixed>  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhere(array|Closure|string $column, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
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
     * @param  scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereContains(string $column, mixed $value): PaperQueryBuilder
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
        return static::query()->whereDate($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereDate(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->orWhereDate($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereYear(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->whereYear($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereYear(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->orWhereYear($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereMonth(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->whereMonth($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereMonth(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->orWhereMonth($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function whereDay(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->whereDay($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereDay(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->orWhereDay($column, $operator, $value);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function has(string $relation, string $operator = '>=', int $count = 1): PaperQueryBuilder
    {
        return static::query()->has($relation, $operator, $count);
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
    public static function doesntHave(string $relation): PaperQueryBuilder
    {
        return static::query()->doesntHave($relation);
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
        return static::query()->whereRelation($relation, $column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
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
     * @param  array<int, string>|string  $relations
     * @return PaperQueryBuilder<static>
     */
    public static function with($relations, string ...$more): PaperQueryBuilder
    {
        $names = is_string($relations) ? [$relations, ...$more] : $relations;

        return static::query()->with(array_values($names));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = []): static
    {
        $model = new static;
        $model->fill($attributes);

        $slug = static::keyToString($model->getAttribute($model->getKeyName()));

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
        $existing = static::firstWhereAttributes($attributes);

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
        $existing = static::firstWhereAttributes($attributes);

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
        $existing = static::firstWhereAttributes($attributes);

        if ($existing !== null) {
            $existing->fill($values);
            $existing->save();

            return $existing;
        }

        return static::create(array_merge($attributes, $values));
    }

    public function getKeyName(): string
    {
        return 'slug';
    }

    public function getContentPath(): string
    {
        $attribute = (new ReflectionClass(static::class))->getAttributes(ContentPath::class)[0] ?? null;

        return $attribute?->newInstance()->path ?? 'content';
    }

    public function getFilePath(): string
    {
        $slug = static::keyToString($this->getAttribute($this->getKeyName()));

        if ($slug === '') {
            throw InvalidSlugException::missing();
        }

        PaperQueryBuilder::guardSlug($slug);

        $resolved = PaperQueryBuilder::resolveFor(static::class);
        $driver = $resolved['driver'];
        $directory = PaperQueryBuilder::contentPathFor(static::class);

        if ($this->paperExtension === null) {
            $manifest = app(PaperManifest::class);
            $stored = static::keyToString($this->getRawOriginal($this->getKeyName())) ?: $slug;

            $record = $this->exists
                ? $manifest->record($resolved['adapter'], $driver, $directory, $stored, $resolved['nested'])
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
        return static::query()->where($field ?? $this->getRouteKeyName(), static::keyToString($value))->first();
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

        if (! $relation instanceof HasManyPaper) {
            throw new BadMethodCallException(
                sprintf(
                    'Relation %s::%s must return %s for scoped route binding.',
                    static::class,
                    $relationName,
                    HasManyPaper::class,
                )
            );
        }

        $relatedClass = $relation->relatedClass;
        $childField = is_string($field) ? $field : new $relatedClass()->getRouteKeyName();

        return $relation->query()->where($childField, static::keyToString($value))->first();
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
        $slug = static::keyToString($this->getAttribute($this->getKeyName()));

        if ($slug === '') {
            return false;
        }

        PaperQueryBuilder::guardSlug($slug);

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

        $filepath = $this->getFilePath();

        $this->loadPaperBody();

        $attributes = PaperCasts::toStorage($this, $this->getAttributes());

        if ($this->usesTimestamps()) {
            $updatedAt = $this->getUpdatedAtColumn();
            $stored = $manifest->record($adapter, $driver, $path, $slug, $resolved['nested']);

            if ($updatedAt !== null && ($stored === null || ! array_key_exists($updatedAt, $stored['data']))) {
                unset($attributes[$updatedAt]);
            }
        }

        $content = $driver->serialize($attributes);

        $adapter->ensureDirectoryExists(dirname($filepath));

        $success = $adapter->write($filepath, $content);

        if ($success) {
            $this->exists = true;
            $manifest->put($adapter, $driver, $path, $slug, $filepath, $driver->parse($content));

            $original = static::keyToString($this->getRawOriginal($this->getKeyName()));

            // A changed slug moves the record, like Eloquent updating a row by its original key.
            if ($original !== '' && $original !== $slug) {
                $adapter->delete($path.'/'.$original.'.'.$this->paperExtension);
                $manifest->forget($adapter, $path, $original);
            }

            if ($isCreating) {
                $this->wasRecentlyCreated = true;
            } else {
                $this->syncChanges();
            }

            $this->fireModelEvent($isCreating ? 'created' : 'updated', false);
            $this->fireModelEvent('saved', false);

            $this->syncOriginal();
        }

        return $success;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function saveQuietly(array $options = []): bool
    {
        return $this->quietly(fn (): bool => $this->save($options));
    }

    /**
     * @param  array<int, string>|string  $with  Ignored, kept for Eloquent parity.
     */
    public function fresh($with = []): ?static
    {
        if (! $this->exists) {
            return null;
        }

        return static::find($this->getAttribute($this->getKeyName()));
    }

    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $fresh = static::findOrFail($this->getAttribute($this->getKeyName()));
        $this->setRawAttributes($fresh->getAttributes(), true);

        return $this;
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

        $adapter = PaperQueryBuilder::resolveFor(static::class)['adapter'];
        $path = PaperQueryBuilder::contentPathFor(static::class);
        $slug = static::keyToString($this->getAttribute($this->getKeyName()));
        $filepath = $this->getFilePath();

        if (! $adapter->exists($filepath)) {
            return false;
        }

        $deleted = $adapter->delete($filepath);

        if ($deleted) {
            $manifest->forget($adapter, $path, $slug);
            $this->exists = false;
            $this->fireModelEvent('deleted', false);
        }

        return $deleted;
    }

    public function deleteQuietly(): bool
    {
        return $this->quietly(fn (): bool => $this->delete());
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
        $slug = static::keyToString($this->attributes[$this->getKeyName()] ?? null);

        $body = app(PaperManifest::class)->body($resolved['adapter'], $resolved['driver'], $path, $slug, $resolved['nested']);
        $attributes = PaperCasts::fromStorage($this, [$column => $body]);

        $this->attributes[$column] = $attributes[$column];
        $this->original[$column] = $attributes[$column];
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
