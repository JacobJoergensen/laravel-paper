<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedRouteBindingException;
use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;
use ReflectionClass;

/**
 * @mixin Model
 */
trait Paper
{
    public static function resetPaperState(): void
    {
        PaperQueryBuilder::forgetCache(static::class);
    }

    public static function query(): PaperQueryBuilder
    {
        return PaperQueryBuilder::forModel(static::class);
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     * @return Collection<int, static>
     */
    public static function all($columns = ['*']): Collection
    {
        /** @var Collection<int, static> */
        return static::query()->get();
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     */
    public static function find(mixed $id, $columns = ['*']): ?static
    {
        /** @var ?static */
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
        /** @var Collection<int, static> */
        return static::query()->findMany($ids);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public static function where(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public static function orWhere(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->orWhere($column, $operator, $value);
    }

    /**
     * @param  (callable(PaperQueryBuilder, mixed): mixed)|null  $callback
     * @param  (callable(PaperQueryBuilder, mixed): mixed)|null  $default
     */
    public static function when(mixed $value, ?callable $callback = null, ?callable $default = null): PaperQueryBuilder
    {
        return static::query()->when($value, $callback, $default);
    }

    /**
     * @param  array<int, scalar>  $values
     */
    public static function whereIn(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereIn($column, $values);
    }

    /**
     * @param  array<int, scalar>  $values
     */
    public static function whereNotIn(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereNotIn($column, $values);
    }

    /**
     * @param  scalar  $value
     */
    public static function whereContains(string $column, mixed $value): PaperQueryBuilder
    {
        return static::query()->whereContains($column, $value);
    }

    public static function whereLike(string $column, string $value, bool $caseSensitive = false): PaperQueryBuilder
    {
        return static::query()->whereLike($column, $value, $caseSensitive);
    }

    public static function orWhereLike(string $column, string $value, bool $caseSensitive = false): PaperQueryBuilder
    {
        return static::query()->orWhereLike($column, $value, $caseSensitive);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public static function whereAny(array $columns, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->whereAny($columns, $operator, $value);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public static function whereAll(array $columns, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->whereAll($columns, $operator, $value);
    }

    public static function whereNull(string $column): PaperQueryBuilder
    {
        return static::query()->whereNull($column);
    }

    public static function whereNotNull(string $column): PaperQueryBuilder
    {
        return static::query()->whereNotNull($column);
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public static function whereBetween(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereBetween($column, $values);
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public static function whereNotBetween(string $column, array $values): PaperQueryBuilder
    {
        return static::query()->whereNotBetween($column, $values);
    }

    public static function latest(string $column = 'updated_at'): PaperQueryBuilder
    {
        return static::query()->latest($column);
    }

    public static function oldest(string $column = 'updated_at'): PaperQueryBuilder
    {
        return static::query()->oldest($column);
    }

    public static function inRandomOrder(): PaperQueryBuilder
    {
        return static::query()->inRandomOrder();
    }

    public static function first(): ?static
    {
        /** @var ?static */
        return static::query()->first();
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public static function firstWhere(string $column, mixed $operator = null, mixed $value = null): ?static
    {
        /** @var ?static */
        return static::query()->firstWhere($column, $operator, $value);
    }

    public static function firstOrFail(): static
    {
        /** @var static */
        return static::query()->firstOrFail();
    }

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
        /** @var callable(Collection<int, Model>, int): mixed $callback */
        return static::query()->chunk($count, $callback);
    }

    /**
     * @param  callable(static, array-key): mixed  $callback
     */
    public static function each(callable $callback, int $count = 1000): bool
    {
        /** @var callable(Model, array-key): mixed $callback */
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
        /** @var LengthAwarePaginator<int, static> */
        return static::query()->paginate($perPage, $page);
    }

    /**
     * @return Paginator<int, static>
     */
    public static function simplePaginate(int $perPage = 15, ?int $page = null): Paginator
    {
        /** @var Paginator<int, static> */
        return static::query()->simplePaginate($perPage, $page);
    }

    /**
     * @param  array<int, string>|string  $relations
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

    /**
     * @param  ?string  $field
     */
    public function resolveRouteBinding(mixed $value, $field = null): ?static
    {
        /** @var ?static */
        return static::query()->where($field ?? $this->getRouteKeyName(), static::keyToString($value))->first();
    }

    /**
     * @param  string  $childType
     */
    public function resolveChildRouteBinding($childType, mixed $value, mixed $field): never
    {
        throw UnsupportedRouteBindingException::scopedChild($childType);
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
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $cache = app(CacheContract::class);

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

        $filepath = $this->paperFilepath($path, $slug, $driver, $isCreating, $adapter);
        $attributes = PaperCasts::toStorage($this, $this->getAttributes());

        if ($this->usesTimestamps()) {
            $updatedAt = $this->getUpdatedAtColumn();

            if ($updatedAt !== null) {
                unset($attributes[$updatedAt]);
            }
        }

        $content = $driver->serialize($attributes);

        $adapter->ensureDirectoryExists($path);

        $success = $adapter->write($filepath, $content);

        if ($success) {
            $this->exists = true;
            $cache->forget($adapter->cacheKey($filepath));

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
     * @param  class-string<Model>  $related
     */
    protected function belongsToPaper(string $related, ?string $foreignKey = null): BelongsToPaper
    {
        $foreignKey ??= Str::snake(class_basename($related)).'_slug';

        return new BelongsToPaper($this, $related, $foreignKey);
    }

    /**
     * @param  class-string<Model>  $related
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

        $cache = app(CacheContract::class);

        $resolved = PaperQueryBuilder::resolveFor(static::class);
        $driver = $resolved['driver'];
        $path = PaperQueryBuilder::contentPathFor(static::class);
        $adapter = $resolved['adapter'];
        $slug = static::keyToString($this->getAttribute($this->getKeyName()));

        PaperQueryBuilder::guardSlug($slug);

        foreach ($driver->extensions() as $ext) {
            $filepath = $path.'/'.$slug.'.'.$ext;

            if ($adapter->exists($filepath)) {
                $cache->forget($adapter->cacheKey($filepath));
                $deleted = $adapter->delete($filepath);

                if ($deleted) {
                    $this->exists = false;
                    $this->fireModelEvent('deleted', false);
                }

                return $deleted;
            }
        }

        return false;
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

    private function paperFilepath(string $directory, string $slug, DriverContract $driver, bool $isCreating, StorageAdapterContract $adapter): string
    {
        $extensions = $driver->extensions();

        if (! $isCreating) {
            foreach ($extensions as $extension) {
                $existing = $directory.'/'.$slug.'.'.$extension;

                if ($adapter->exists($existing)) {
                    return $existing;
                }
            }
        }

        return $directory.'/'.$slug.'.'.$extensions[0];
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

        /** @var ?static */
        return $query->first();
    }
}
