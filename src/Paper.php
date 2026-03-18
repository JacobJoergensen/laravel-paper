<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
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

    public static function first(): ?static
    {
        /** @var ?static */
        return static::query()->first();
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
