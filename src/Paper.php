<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;

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
        return static::query()->get();
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     */
    public static function find(mixed $id, $columns = ['*']): ?static
    {
        /** @var ?static */
        return static::query()->find((string) $id);
    }

    /**
     * @param  array<int, string>|string  $columns  Ignored, kept for Eloquent parity.
     */
    public static function findOrFail(mixed $id, $columns = ['*']): static
    {
        $model = static::find($id, $columns);

        if ($model === null) {
            throw new ModelNotFoundException()->setModel(static::class, [$id]);
        }

        return $model;
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

    public static function where(string $column, mixed $operator, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

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
     */
    public static function whereAny(array $columns, mixed $operator = null, mixed $value = null): PaperQueryBuilder
    {
        return static::query()->whereAny($columns, $operator, $value);
    }

    /**
     * @param  array<int, string>  $columns
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

    public static function latest(string $column = 'created_at'): PaperQueryBuilder
    {
        return static::query()->latest($column);
    }

    public static function oldest(string $column = 'created_at'): PaperQueryBuilder
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

    public static function count(): int
    {
        return static::query()->count();
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
    public static function pluck(string $column): Collection
    {
        return static::query()->pluck($column);
    }

    public static function value(string $column): mixed
    {
        return static::query()->value($column);
    }

    public static function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        return static::query()->paginate($perPage, $page);
    }

    public static function simplePaginate(int $perPage = 15, ?int $page = null): Paginator
    {
        return static::query()->simplePaginate($perPage, $page);
    }

    /**
     * @param  array<int, string>|string  $relations
     */
    public static function with($relations): PaperQueryBuilder
    {
        return static::query()->with(is_string($relations) ? func_get_args() : $relations);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = []): static
    {
        $model = new static;
        $model->fill($attributes);

        $slug = (string) $model->getAttribute($model->getKeyName());

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
        return false;
    }

    public function save(array $options = []): bool
    {
        $cache = app(CacheContract::class);

        $resolved = PaperQueryBuilder::resolveFor(static::class);
        $driver = $resolved['driver'];
        $path = $resolved['contentPath'];
        $adapter = $resolved['adapter'];
        $slug = (string) $this->getAttribute($this->getKeyName());

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
        $path = $resolved['contentPath'];
        $adapter = $resolved['adapter'];
        $slug = $this->getAttribute($this->getKeyName());

        PaperQueryBuilder::guardSlug((string) $slug);

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function firstWhereAttributes(array $attributes): ?static
    {
        $query = static::query();

        foreach ($attributes as $column => $value) {
            $query->where($column, $value);
        }

        /** @var ?static */
        return $query->first();
    }
}
