<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidDriverException;
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

    public static function bootPaper(): void
    {
        static::resolveAttributes();
    }

    public static function resetPaperState(): void
    {
        unset(self::$paperDrivers[static::class]);
        unset(self::$paperContentPaths[static::class]);
    }

    public static function query(): PaperQueryBuilder
    {
        static::resolveAttributes();

        /** @var class-string<Model> $class */
        $class = static::class;

        return new PaperQueryBuilder(
            app(Filesystem::class),
            static::$paperDrivers[$class],
            app(CacheContract::class),
            static::$paperContentPaths[$class],
            $class,
        );
    }

    /**
     * @return Collection<int, static>
     */
    public static function all($columns = ['*']): Collection
    {
        return static::query()->get();
    }

    public static function find(mixed $id, $columns = ['*']): ?static
    {
        /** @var ?static */
        return static::query()->find((string) $id);
    }

    public static function findOrFail(mixed $id, $columns = ['*']): static
    {
        $model = static::find($id, $columns);

        if ($model === null) {
            throw new ModelNotFoundException()->setModel(static::class, [$id]);
        }

        return $model;
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

    public static function first(): ?static
    {
        /** @var ?static */
        return static::query()->first();
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

    public static function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        return static::query()->paginate($perPage, $page);
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
        static::resolveAttributes();

        $files = app(Filesystem::class);
        $cache = app(CacheContract::class);

        $class = static::class;
        $driver = static::$paperDrivers[$class];
        $path = static::$paperContentPaths[$class];
        $slug = $this->getAttribute($this->getKeyName());

        if (empty($slug)) {
            return false;
        }

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

        $filepath = $path.'/'.$slug.'.'.$driver->extensions()[0];
        $content = $driver->serialize($this->getAttributes());

        $success = $files->put($filepath, $content) !== false;

        if ($success) {
            $this->exists = true;
            $cache->forget($filepath);

            $this->fireModelEvent($isCreating ? 'created' : 'updated', false);
            $this->fireModelEvent('saved', false);
        }

        return $success;
    }

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

        return $related::find($key);
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return Collection<int, TRelated>
     */
    protected function hasManyPaper(string $related, ?string $foreignKey = null): Collection
    {
        $foreignKey ??= Str::snake(class_basename(static::class)).'_slug';
        $key = $this->getAttribute($this->getKeyName());

        return $related::where($foreignKey, $key)->get();
    }

    public function delete(): bool
    {
        static::resolveAttributes();

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $files = app(Filesystem::class);
        $cache = app(CacheContract::class);

        $class = static::class;
        $driver = static::$paperDrivers[$class];
        $path = static::$paperContentPaths[$class];
        $slug = $this->getAttribute($this->getKeyName());

        foreach ($driver->extensions() as $ext) {
            $filepath = $path.'/'.$slug.'.'.$ext;

            if ($files->exists($filepath)) {
                $cache->forget($filepath);
                $deleted = $files->delete($filepath);

                if ($deleted) {
                    $this->fireModelEvent('deleted', false);
                }

                return $deleted;
            }
        }

        return false;
    }

    private static function resolveAttributes(): void
    {
        $class = static::class;

        if (isset(static::$paperDrivers[$class], static::$paperContentPaths[$class])) {
            return;
        }

        $reflection = new ReflectionClass($class);

        $driverAttribute = $reflection->getAttributes(Driver::class)[0] ?? null;
        $pathAttribute = $reflection->getAttributes(ContentPath::class)[0] ?? null;

        $driverName = $driverAttribute?->newInstance()->name ?? 'markdown';
        $contentPath = $pathAttribute?->newInstance()->path ?? 'content';

        static::$paperDrivers[$class] = static::resolveDriver($driverName);
        static::$paperContentPaths[$class] = base_path($contentPath);
    }

    private static function resolveDriver(string $name): DriverContract
    {
        $drivers = app('paper.drivers');

        if (! isset($drivers[$name])) {
            throw InvalidDriverException::notFound($name);
        }

        return app($drivers[$name]);
    }
}
