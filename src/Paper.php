<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Attributes\Timestamps;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\ScopeContract;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Exceptions\DuplicateSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedDatabaseQueryException;
use ReflectionClass;

/**
 * @mixin Model
 */
trait Paper
{
    /** @var array<class-string, DriverContract> */
    protected static array $paperDrivers = [];

    /** @var array<class-string, string> */
    protected static array $paperContentPaths = [];

    /** @var array<class-string, bool> */
    protected static array $paperTimestamps = [];

    private ?string $paperExtension = null;

    public static function resetPaperState(): void
    {
        unset(self::$paperDrivers[static::class]);
        unset(self::$paperContentPaths[static::class]);
        unset(self::$paperTimestamps[static::class]);
    }

    /**
     * @param  mixed  $scope
     * @param  mixed  $implementation
     */
    public static function addGlobalScope($scope, $implementation = null): void
    {
        $resolved = $implementation ?? $scope;

        if (is_string($resolved) && is_subclass_of($resolved, ScopeContract::class)) {
            $resolved = new $resolved;
        }

        if (! $resolved instanceof ScopeContract) {
            parent::addGlobalScope($scope, $implementation); // @phpstan-ignore argument.type, argument.type

            return;
        }

        $identifier = is_string($scope) ? $scope : $resolved::class;

        /** @var array<class-string, array<string, mixed>> $scopes */
        $scopes = static::getAllGlobalScopes();
        $scopes[static::class][$identifier] = $resolved;

        static::setAllGlobalScopes($scopes);
    }

    /**
     * @return PaperQueryBuilder<static>
     */
    public static function query(): PaperQueryBuilder
    {
        static::resolveAttributes();

        /** @var class-string<static> $class */
        $class = static::class;
        $directory = new static()->paperDirectory();

        $builder = new PaperQueryBuilder(
            app(Filesystem::class),
            static::$paperDrivers[$class],
            app(CacheContract::class),
            $directory,
            $class,
        );

        $builder->applyGlobalScopes();

        return $builder;
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
     * @param  array<array-key, mixed>|string  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function where(array|string $column, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        return static::query()->where($column, $operator, $value);
    }

    /**
     * @param  array<array-key, mixed>|string  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return PaperQueryBuilder<static>
     */
    public static function orWhere(array|string $column, mixed $operator = null, mixed $value = null): PaperQueryBuilder
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
    public static function lazy(): LazyCollection
    {
        return static::query()->lazy();
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
        static::resolveAttributes();

        return static::$paperContentPaths[static::class];
    }

    public function getFilePath(): string
    {
        $slug = static::keyToString($this->getAttribute($this->getKeyName()));

        if ($slug === '') {
            throw InvalidSlugException::missing();
        }

        PaperQueryBuilder::guardSlug($slug);

        $directory = $this->paperDirectory();

        return $directory.'/'.$slug.'.'.$this->storedExtension($directory);
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

        if (! $relation instanceof Collection) {
            throw new BadMethodCallException(
                sprintf(
                    'Relation %s::%s must return %s for scoped route binding.',
                    static::class,
                    $relationName,
                    Collection::class,
                )
            );
        }

        $children = $relation->whereInstanceOf(Model::class);
        $firstChild = $children->first();

        if ($firstChild === null) {
            return null;
        }

        $childField = $field ?? $firstChild->getRouteKeyName();

        return $children->firstWhere($childField, static::keyToString($value));
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
        static::resolveAttributes();

        return static::$paperTimestamps[static::class];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        static::resolveAttributes();

        $cache = app(CacheContract::class);
        $driver = static::$paperDrivers[static::class];

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
        $slug = static::keyToString($this->getAttribute($this->getKeyName()));

        if ($slug === '') {
            return false;
        }

        PaperQueryBuilder::guardSlug($slug);

        $original = static::keyToString($this->getRawOriginal($this->getKeyName()));
        $isRenaming = $original !== '' && $original !== $slug;
        $directory = $this->paperDirectory();
        $existing = $this->paperFilepath($directory, $slug, $driver);

        if ($isCreating && is_file($existing)) {
            throw DuplicateSlugException::forSlug($slug, $existing);
        }

        // A rename would write over the record already stored under the new slug.
        if ($isRenaming && is_file($existing)) {
            return false;
        }

        $extension = $this->storedExtension($directory);
        $filepath = $directory.'/'.$slug.'.'.$extension;
        $source = $isRenaming ? $directory.'/'.$original.'.'.$extension : $filepath;

        $attributes = PaperCasts::toStorage($this, $this->getAttributes());
        $mtimeColumn = null;

        if ($this->usesTimestamps()) {
            $updatedAt = $this->getUpdatedAtColumn();
            $stored = is_file($source) ? $driver->parse($source) : [];

            if ($updatedAt !== null && ! array_key_exists($updatedAt, $stored)) {
                unset($attributes[$updatedAt]);
                $mtimeColumn = $updatedAt;
            }
        }

        $content = $driver->serialize($attributes);

        app(Filesystem::class)->ensureDirectoryExists($directory);

        $tempPath = @tempnam(dirname($filepath), '.paper-');

        if ($tempPath === false) {
            return false;
        }

        @chmod($tempPath, 0666 & ~umask());

        $success = @file_put_contents($tempPath, $content) !== false
            && @rename($tempPath, $filepath);

        if (! $success) {
            @unlink($tempPath);
        }

        if ($success) {
            if ($isRenaming && is_file($source)) {
                $cache->forget($source);

                if (! @unlink($source)) {
                    @unlink($filepath);

                    return false;
                }
            }

            $this->exists = true;
            $cache->forget($filepath);

            if ($mtimeColumn !== null) {
                $this->attributes[$mtimeColumn] = filemtime($filepath);
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

        $slug = static::keyToString($this->getAttribute($this->getKeyName()));

        return static::withoutGlobalScopes()->find($slug);
    }

    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $slug = static::keyToString($this->getAttribute($this->getKeyName()));
        $fresh = $this->fresh() ?? throw new ModelNotFoundException()->setModel(static::class, [$slug]);
        $this->setRawAttributes($fresh->getAttributes(), true);

        return $this;
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return ?TRelated
     */
    protected function belongsToPaper(string $related, ?string $foreignKey = null): ?Model
    {
        $foreignKey ??= Str::snake(class_basename($related)).'_slug';
        $key = $this->getAttribute($foreignKey);

        if ($key === null) {
            return null;
        }

        /** @var ?TRelated */
        return $related::find($key); // @phpstan-ignore staticMethod.notFound
    }

    /**
     * Reads every related file on each call. Recommended to not use this in a loop.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return Collection<int, TRelated>
     */
    protected function hasManyPaper(string $related, ?string $foreignKey = null): Collection
    {
        $foreignKey ??= Str::snake(class_basename(static::class)).'_slug';
        $key = $this->getAttribute($this->getKeyName());

        /** @var Collection<int, TRelated> */
        return $related::where($foreignKey, $key)->get(); // @phpstan-ignore staticMethod.notFound, method.nonObject
    }

    public function delete(): bool
    {
        static::resolveAttributes();

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $files = app(Filesystem::class);
        $cache = app(CacheContract::class);

        $slug = static::keyToString($this->getAttribute($this->getKeyName()));
        $stored = static::keyToString($this->getRawOriginal($this->getKeyName()));

        // A record is deleted by the key it was loaded under, like Eloquent, so a slug changed
        // in a listener or left dirty on the model cannot point to delete at another record.
        if ($stored !== '' && $stored !== $slug) {
            $slug = $stored;
        }

        PaperQueryBuilder::guardSlug($slug);

        $directory = $this->paperDirectory();
        $filepath = $directory.'/'.$slug.'.'.$this->storedExtension($directory);

        if (! $files->exists($filepath)) {
            return false;
        }

        $cache->forget($filepath);
        $deleted = $files->delete($filepath);

        if ($deleted) {
            $this->exists = false;
            $this->fireModelEvent('deleted', false);
        }

        return $deleted;
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

    private function paperFilepath(string $directory, string $slug, DriverContract $driver): string
    {
        $extensions = $driver->extensions();

        foreach ($extensions as $extension) {
            $existing = $directory.'/'.$slug.'.'.$extension;

            if (is_file($existing)) {
                return $existing;
            }
        }

        return $directory.'/'.$slug.'.'.$extensions[0];
    }

    private function paperDirectory(): string
    {
        $path = $this->getContentPath();
        $isAbsolute = preg_match('~^([A-Za-z]:)?[/\\\\]~', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    private function storedExtension(string $directory): string
    {
        if ($this->paperExtension !== null) {
            return $this->paperExtension;
        }

        static::resolveAttributes();

        $stored = static::keyToString($this->getRawOriginal($this->getKeyName()));
        $slug = $stored !== '' ? $stored : static::keyToString($this->getAttribute($this->getKeyName()));

        $driver = static::$paperDrivers[static::class];
        $filepath = $this->paperFilepath($directory, $slug, $driver);
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);

        // Only kept once stored, because a new record's slug may still point at another record's file.
        if ($this->exists) {
            $this->paperExtension = $extension;
        }

        return $extension;
    }

    private static function resolveAttributes(): void
    {
        $class = static::class;

        if (isset(static::$paperDrivers[$class], static::$paperContentPaths[$class])) {
            return;
        }

        $driverName = static::paperAttribute(Driver::class)->name ?? 'markdown';
        $contentPath = static::paperAttribute(ContentPath::class)->path ?? 'content';

        static::$paperDrivers[$class] = static::resolveDriver($driverName);
        static::$paperContentPaths[$class] = $contentPath;
        static::$paperTimestamps[$class] = static::paperAttribute(Timestamps::class) !== null;
    }

    /**
     * @template TAttribute of object
     *
     * @param  class-string<TAttribute>  $attribute
     * @return ?TAttribute
     */
    private static function paperAttribute(string $attribute): ?object
    {
        $reflection = new ReflectionClass(static::class);

        do {
            $declared = $reflection->getAttributes($attribute)[0] ?? null;

            if ($declared !== null) {
                return $declared->newInstance();
            }
        } while ($reflection = $reflection->getParentClass());

        return null;
    }

    private static function resolveDriver(string $name): DriverContract
    {
        return app(DriverRegistry::class)->resolve($name);
    }

    private static function keyToString(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
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
