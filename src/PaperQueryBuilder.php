<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
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
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Disk;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Attributes\Timestamps;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\MissingTimestampsException;
use JacobJoergensen\LaravelPaper\Relations\PaperRelation;
use JacobJoergensen\LaravelPaper\StorageAdapters\DiskAdapter;
use JacobJoergensen\LaravelPaper\StorageAdapters\LocalAdapter;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * @template TModel of Model&PaperModel
 */
final class PaperQueryBuilder
{
    /** @var list<array{type: string, column?: string, second?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool, wheres?: list<array<string, mixed>>, relation?: string, count?: int, constraint?: ?Closure, boolean: string}> */
    private array $wheres = [];

    /** @var array<string, callable(Model): int> */
    private array $hasCounters = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limitValue = null;

    private int $offsetValue = 0;

    private bool $randomOrder = false;

    /** @var list<string> */
    private array $with = [];

    /** @var ?TModel */
    private ?Model $model = null;

    private bool $updatedAtResolved = false;

    private ?string $updatedAtColumn = null;

    /** @var array<class-string<PaperModel>, DriverContract> */
    private static array $driverCache = [];

    /** @var array<class-string<PaperModel>, bool> */
    private static array $usesDiskCache = [];

    /** @var array<class-string<PaperModel>, StorageAdapterContract> */
    private static array $adapterCache = [];

    /** @var array<class-string<PaperModel>, bool> */
    private static array $timestampsCache = [];

    /** @var array<class-string<PaperModel>, bool> */
    private static array $nestedCache = [];

    /** @var array<string, array{driver: DriverContract, adapter: StorageAdapterContract, usesDisk: bool, nested: bool}> */
    private static array $fakes = [];

    /**
     * @param  class-string<TModel>  $modelClass
     */
    public function __construct(
        private readonly StorageAdapterContract $adapter,
        private readonly DriverContract $driver,
        private readonly PaperManifest $manifest,
        private readonly string $contentPath,
        private readonly string $modelClass,
    ) {}

    /**
     * @template TTarget of Model&PaperModel
     *
     * @param  class-string<TTarget>  $modelClass
     * @return self<TTarget>
     */
    public static function forModel(string $modelClass): self
    {
        $resolved = self::resolveFor($modelClass);

        return new self(
            $resolved['adapter'],
            $resolved['driver'],
            app(PaperManifest::class),
            self::contentPathFor($modelClass),
            $modelClass,
        );
    }

    /**
     * @param  class-string<PaperModel>  $modelClass
     * @return array{driver: DriverContract, adapter: StorageAdapterContract, usesDisk: bool, nested: bool}
     */
    public static function resolveFor(string $modelClass): array
    {
        if (isset(self::$fakes[$modelClass])) {
            return self::$fakes[$modelClass];
        }

        if (! isset(self::$driverCache[$modelClass])) {
            $reflection = new ReflectionClass($modelClass);

            $driverAttribute = $reflection->getAttributes(Driver::class)[0] ?? null;
            $diskAttribute = $reflection->getAttributes(Disk::class)[0] ?? null;
            $contentPathAttribute = $reflection->getAttributes(ContentPath::class)[0] ?? null;

            $driverName = $driverAttribute?->newInstance()->name ?? 'markdown';
            $diskName = $diskAttribute?->newInstance()->name;

            self::$driverCache[$modelClass] = app(DriverRegistry::class)->resolve($driverName);
            self::$timestampsCache[$modelClass] = $reflection->getAttributes(Timestamps::class) !== [];
            self::$nestedCache[$modelClass] = $contentPathAttribute?->newInstance()->nested ?? false;

            if ($diskName === null) {
                self::$adapterCache[$modelClass] = new LocalAdapter(app(Filesystem::class));
                self::$usesDiskCache[$modelClass] = false;
            } else {
                $disk = app(StorageFactory::class)->disk($diskName);
                self::$adapterCache[$modelClass] = new DiskAdapter($disk, $diskName);
                self::$usesDiskCache[$modelClass] = true;
            }
        }

        return [
            'driver' => self::$driverCache[$modelClass],
            'adapter' => self::$adapterCache[$modelClass],
            'usesDisk' => self::$usesDiskCache[$modelClass],
            'nested' => self::$nestedCache[$modelClass],
        ];
    }

    /**
     * @param  class-string<PaperModel>  $modelClass
     */
    public static function contentPathFor(string $modelClass): string
    {
        $usesDisk = self::resolveFor($modelClass)['usesDisk'];

        $path = new $modelClass()->getContentPath();

        return $usesDisk ? $path : base_path($path);
    }

    /**
     * @param  class-string<PaperModel>  $modelClass
     */
    public static function usesTimestamps(string $modelClass): bool
    {
        self::resolveFor($modelClass);

        return self::$timestampsCache[$modelClass];
    }

    /**
     * @param  ?class-string<PaperModel>  $modelClass
     */
    public static function forgetCache(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            self::$driverCache = [];
            self::$usesDiskCache = [];
            self::$adapterCache = [];
            self::$timestampsCache = [];
            self::$nestedCache = [];
            self::$fakes = [];

            return;
        }

        unset(
            self::$driverCache[$modelClass],
            self::$usesDiskCache[$modelClass],
            self::$adapterCache[$modelClass],
            self::$timestampsCache[$modelClass],
            self::$nestedCache[$modelClass],
            self::$fakes[$modelClass],
        );
    }

    /**
     * @param  class-string<PaperModel>  $modelClass
     *
     * @internal Registers an in-memory adapter for tests. Use PaperFake, not this directly.
     */
    public static function fake(string $modelClass, StorageAdapterContract $adapter): void
    {
        $resolved = self::resolveFor($modelClass);

        self::$fakes[$modelClass] = [
            'driver' => $resolved['driver'],
            'adapter' => $adapter,
            'usesDisk' => $resolved['usesDisk'],
            'nested' => $resolved['nested'],
        ];
    }

    public static function forgetFakes(): void
    {
        self::$fakes = [];
    }

    /**
     * Records get their own instance in fileToModel().
     *
     * @return TModel
     */
    private function model(): Model
    {
        return $this->model ??= new $this->modelClass;
    }

    private function nested(): bool
    {
        return self::resolveFor($this->modelClass)['nested'];
    }

    /**
     * Rejects slugs that would escape the content directory.
     */
    public static function guardSlug(string $slug): void
    {
        $malformed = str_contains($slug, '\\')
            || str_contains($slug, "\0")
            || str_starts_with($slug, '/')
            || str_ends_with($slug, '/')
            || str_contains($slug, '//');

        if ($malformed || self::hasTraversalSegment($slug)) {
            throw InvalidSlugException::forSlug($slug);
        }
    }

    private static function hasTraversalSegment(string $slug): bool
    {
        foreach (explode('/', $slug) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ?TModel
     */
    public function find(string $slug): ?Model
    {
        $model = $this->locate($slug);

        if ($model !== null) {
            if ($this->with !== []) {
                $this->eagerLoadRelations(collect([$model]));
            }

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

        $this->eagerLoadRelations($collection);

        $collection->each($this->fireRetrieved(...));

        return $collection;
    }

    /**
     * @return ?TModel
     */
    private function locate(string $slug): ?Model
    {
        self::guardSlug($slug);

        try {
            $entry = $this->manifest->record($this->adapter, $this->driver, $this->contentPath, $slug, $this->nested());
        } catch (ContentPathNotFoundException) {
            throw ContentPathNotFoundException::forPath($this->contentPath, $this->modelClass);
        }

        if ($entry === null) {
            return null;
        }

        return $this->hydrate($entry['slug'], $entry['mtime'], $entry['data']);
    }

    /**
     * @param  (Closure(static): mixed)|string|array<array-key, mixed>  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function where(array|Closure|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        if ($column instanceof Closure) {
            return $this->whereGroup($column, $boolean);
        }

        [$operator, $value] = $this->resolveOperator($operator, $value);

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => is_scalar($value) ? $value : null,
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
                    $query->where($key, '=', is_scalar($value) ? $value : null);

                    continue;
                }

                $column = is_array($value) ? ($value[0] ?? null) : null;

                if (! is_string($column)) {
                    throw new InvalidArgumentException('Each array condition must be [column, value] or [column, operator, value].');
                }

                $operator = $value[1] ?? null;
                $bound = $value[2] ?? null;
                $query->where($column, is_scalar($operator) ? $operator : null, is_scalar($bound) ? $bound : null);
            }
        }, $boolean);
    }

    /**
     * @return array{string, mixed}
     */
    private function resolveOperator(mixed $operator, mixed $value): array
    {
        if ($value === null && ! in_array($operator, ['=', '==', '===', '!=', '<>', '!==', '>', '>=', '<', '<=', 'like'], true)) {
            $value = $operator;
            $operator = '=';
        }

        return [is_string($operator) ? $operator : '=', $value];
    }

    /**
     * @param  (Closure(static): mixed)|string|array<array-key, mixed>  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function orWhere(array|Closure|string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->where($column, $operator, $value, 'or');
    }

    private function whereGroup(callable $callback, string $boolean): static
    {
        $nested = new self($this->adapter, $this->driver, $this->manifest, $this->contentPath, $this->modelClass);
        $callback($nested);

        $this->wheres[] = [
            'type' => 'group',
            'wheres' => $nested->wheres,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->addDateWhere('date', $column, $operator, $value, $boolean);
    }

    public function orWhereDate(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateWhere('date', $column, $operator, $value, 'or');
    }

    public function whereYear(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->addDateWhere('year', $column, $operator, $value, $boolean);
    }

    public function orWhereYear(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateWhere('year', $column, $operator, $value, 'or');
    }

    public function whereMonth(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->addDateWhere('month', $column, $operator, $value, $boolean);
    }

    public function orWhereMonth(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateWhere('month', $column, $operator, $value, 'or');
    }

    public function whereDay(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->addDateWhere('day', $column, $operator, $value, $boolean);
    }

    public function orWhereDay(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateWhere('day', $column, $operator, $value, 'or');
    }

    private function addDateWhere(string $type, string $column, mixed $operator, mixed $value, string $boolean): static
    {
        [$operator, $value] = $this->resolveOperator($operator, $value);

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
            'value' => is_scalar($value) ? $value : null,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'and'): static
    {
        return $this->addHasWhere($relation, null, $operator, $count, $boolean);
    }

    public function orHas(string $relation, string $operator = '>=', int $count = 1): static
    {
        return $this->addHasWhere($relation, null, $operator, $count, 'or');
    }

    public function doesntHave(string $relation, string $boolean = 'and'): static
    {
        return $this->addHasWhere($relation, null, '<', 1, $boolean);
    }

    public function orDoesntHave(string $relation): static
    {
        return $this->addHasWhere($relation, null, '<', 1, 'or');
    }

    public function whereHas(string $relation, ?Closure $constraint = null, string $operator = '>=', int $count = 1, string $boolean = 'and'): static
    {
        return $this->addHasWhere($relation, $constraint, $operator, $count, $boolean);
    }

    public function orWhereHas(string $relation, ?Closure $constraint = null, string $operator = '>=', int $count = 1): static
    {
        return $this->addHasWhere($relation, $constraint, $operator, $count, 'or');
    }

    public function whereDoesntHave(string $relation, ?Closure $constraint = null, string $boolean = 'and'): static
    {
        return $this->addHasWhere($relation, $constraint, '<', 1, $boolean);
    }

    public function orWhereDoesntHave(string $relation, ?Closure $constraint = null): static
    {
        return $this->addHasWhere($relation, $constraint, '<', 1, 'or');
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function whereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->addHasWhere($relation, fn (self $query): mixed => $query->where($column, $operator, $value), '>=', 1, $boolean);
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function orWhereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereRelation($relation, $column, $operator, $value, 'or');
    }

    private function addHasWhere(string $relation, ?Closure $constraint, string $operator, int $count, string $boolean): static
    {
        if (str_contains($relation, '.')) {
            throw new InvalidArgumentException(
                sprintf('Nested relation "%s" is not supported; constrain it with a closure instead.', $relation)
            );
        }

        $this->wheres[] = [
            'type' => 'has',
            'relation' => $relation,
            'constraint' => $constraint,
            'operator' => $operator,
            'count' => $count,
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
        return $this->addRegexpWhere('regexp', $column, $pattern, 'or');
    }

    public function whereNotRegexp(string $column, string $pattern, string $boolean = 'and'): static
    {
        return $this->addRegexpWhere('notRegexp', $column, $pattern, $boolean);
    }

    public function orWhereNotRegexp(string $column, string $pattern): static
    {
        return $this->addRegexpWhere('notRegexp', $column, $pattern, 'or');
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

        if ($this->columnSafe($first) !== $this->columnSafe($second)) {
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
            'operator' => $operator,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereColumn(string $first, string $operator, ?string $second = null): static
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
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
        return $this->whereAny($columns, $operator, $value, 'or');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
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
        return $this->orderBy($column ?? $this->defaultTimeColumn(), 'desc');
    }

    public function oldest(?string $column = null): static
    {
        return $this->orderBy($column ?? $this->defaultTimeColumn());
    }

    private function defaultTimeColumn(): string
    {
        $model = new $this->modelClass;
        $column = $model->getUpdatedAtColumn();

        if (! $model->usesTimestamps() || $column === null) {
            throw MissingTimestampsException::forTimeOrdering($this->modelClass);
        }

        return $column;
    }

    public function inRandomOrder(): static
    {
        $this->randomOrder = true;

        return $this;
    }

    public function limit(int $value): static
    {
        $this->limitValue = $value;

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
     * @param  array<int, string>|string  $relations
     */
    public function with(array|string $relations): static
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        foreach ($relations as $relation) {
            if (! is_string($relation)) {
                continue;
            }

            if (! in_array($relation, $this->with, true)) {
                $this->with[] = $relation;
            }
        }

        return $this;
    }

    /**
     * @return ?TModel
     */
    public function first(): ?Model
    {
        if ($this->orders === [] && $this->with === []) {
            return $this->lazy()->first();
        }

        return $this->limit(1)->get()->first();
    }

    /**
     * @param  (Closure(static): mixed)|string|array<array-key, mixed>  $column
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     * @return ?TModel
     */
    public function firstWhere(array|Closure|string $column, mixed $operator = null, mixed $value = null): ?Model
    {
        return $this->where($column, $operator, $value)->first();
    }

    public function value(string $column): mixed
    {
        $model = $this->first();

        return $model === null ? null : $this->attribute($model, $column);
    }

    /**
     * @return TModel
     */
    public function firstOrFail(): Model
    {
        $model = $this->first();

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel($this->modelClass);
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
            throw (new ModelNotFoundException)->setModel($this->modelClass);
        }

        if (isset($items[1])) {
            throw new MultipleRecordsFoundException(2);
        }

        return $items[0];
    }

    public function count(): int
    {
        if ($this->wheres === []) {
            return count($this->scanSlugs());
        }

        if ($this->canCountRaw()) {
            return $this->records()->filter(fn (array $record): bool => $this->recordMatches($record))->count();
        }

        return $this->lazyModels()->count();
    }

    public function exists(): bool
    {
        if ($this->wheres === []) {
            return $this->scanSlugs() !== [];
        }

        if ($this->canCountRaw()) {
            return $this->records()->contains(fn (array $record): bool => $this->recordMatches($record));
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
        return collect($this->columnValues($column))
            ->flatten(1)
            ->reject(fn (mixed $value): bool => ! is_scalar($value))
            ->countBy();
    }

    /**
     * Spans the whole content directory on purpose; query state like wheres and limits does not apply.
     *
     * @return list<array{path: string, error: string}>
     */
    public function validateFiles(): array
    {
        try {
            $files = $this->manifest->files($this->adapter, $this->driver, $this->contentPath, $this->nested());
        } catch (ContentPathNotFoundException) {
            throw ContentPathNotFoundException::forPath($this->contentPath, $this->modelClass);
        }

        $failures = [];

        foreach ($files as $slug => $info) {
            try {
                $contents = $this->adapter->read($info['path']) ?? '';
                $data = $this->driver->parse($contents);
                $model = $this->hydrate($slug, $info['mtime'], $data);
                $model->toArray();
            } catch (Throwable $e) {
                $failures[] = ['path' => $info['path'], 'error' => $e->getMessage()];
            }
        }

        return $failures;
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
        if ($key === null && $this->isUnconstrained() && $this->columnSafe($column)) {
            return $this->records()->map(function (array $record) use ($column): mixed {
                $row = ['slug' => $record['slug']] + $record['data'];

                return data_get($row, $column);
            });
        }

        return $this->getModels()->pluck($column, $key);
    }

    private function isUnconstrained(): bool
    {
        return $this->wheres === []
            && $this->orders === []
            && ! $this->randomOrder
            && $this->limitValue === null
            && $this->offsetValue === 0;
    }

    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $page ??= Paginator::resolveCurrentPage();

        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        try {
            $this->limitValue = null;
            $this->offsetValue = 0;

            $records = $this->parseFreeRecords();

            if ($records !== null) {
                $total = $records->count();
                $items = $records->slice(($page - 1) * $perPage)
                    ->take($perPage)
                    ->map(fn (array $record) => $this->hydrate($record['slug'], $record['mtime'], $record['data']))
                    ->values();
            } else {
                $all = $this->getModels();
                $total = $all->count();
                $items = $all->slice(($page - 1) * $perPage)->take($perPage)->values();
            }

            $this->eagerLoadRelations($items);
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
        $page ??= Paginator::resolveCurrentPage();

        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        try {
            $this->limitValue = null;
            $this->offsetValue = 0;

            $offset = ($page - 1) * $perPage;
            $records = $this->parseFreeRecords();

            $items = $records !== null
                ? $records->slice($offset)
                    ->take($perPage + 1)
                    ->map(fn (array $record) => $this->hydrate($record['slug'], $record['mtime'], $record['data']))
                    ->values()
                : $this->lazyModels()->skip($offset)->take($perPage + 1)->collect();

            $this->eagerLoadRelations($items);
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

        $this->eagerLoadRelations($models);

        $models->each($this->fireRetrieved(...));

        return $models;
    }

    /**
     * @return Collection<int, TModel>
     */
    private function getModels(): Collection
    {
        $models = new Collection($this->matchingModels());
        $results = $this->applyOrdersAndLimits($models);

        return $this->model()->newCollection($results->all());
    }

    /**
     * @return Generator<int, TModel>
     */
    private function matchingModels(): Generator
    {
        $pushDown = $this->pushDown();

        foreach ($this->records() as $record) {
            if ($pushDown) {
                if ($this->recordMatches($record)) {
                    yield $this->hydrate($record['slug'], $record['mtime'], $record['data']);
                }

                continue;
            }

            $model = $this->hydrate($record['slug'], $record['mtime'], $record['data']);

            if ($this->matchesWheres($model)) {
                yield $model;
            }
        }
    }

    private function pushDown(): bool
    {
        return $this->wheres !== [] && $this->canPushDown();
    }

    private function canPushDown(): bool
    {
        return ! $this->hasRelationWhere($this->wheres)
            && array_all($this->whereColumns($this->wheres), $this->columnSafe(...));
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     */
    private function hasRelationWhere(array $wheres): bool
    {
        foreach ($wheres as $where) {
            if (! is_array($where)) {
                continue;
            }

            if (($where['type'] ?? null) === 'has') {
                return true;
            }

            $nested = $where['wheres'] ?? [];

            if (is_array($nested) && $this->hasRelationWhere($nested)) {
                return true;
            }
        }

        return false;
    }

    private function columnSafe(string $column): bool
    {
        $model = $this->model();
        $root = explode('.', $column, 2)[0];

        return $root !== $this->updatedAtColumn()
            && ! method_exists($model, $root)
            && ! $this->hasCastOrMutator($model, $column)
            && ! $this->hasCastOrMutator($model, $root);
    }

    private function hasCastOrMutator(Model $model, string $column): bool
    {
        return $model->hasCast($column)
            || $model->hasGetMutator($column)
            || $model->hasAttributeGetMutator($column);
    }

    private function canCountRaw(): bool
    {
        return $this->limitValue === null && $this->offsetValue === 0 && $this->pushDown();
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     * @return list<string>
     */
    private function whereColumns(array $wheres): array
    {
        $columns = [];

        foreach ($wheres as $where) {
            if (! is_array($where)) {
                continue;
            }

            if (($where['type'] ?? null) === 'group') {
                $nested = $where['wheres'] ?? [];

                if (is_array($nested)) {
                    $columns = [...$columns, ...$this->whereColumns($nested)];
                }
            } elseif (($where['type'] ?? null) === 'has') {
                continue;
            } elseif (isset($where['column']) && is_string($where['column'])) {
                $columns[] = $where['column'];

                if (isset($where['second']) && is_string($where['second'])) {
                    $columns[] = $where['second'];
                }
            }
        }

        return $columns;
    }

    /**
     * Ignores orders, limits and offsets so aggregates span every matching record.
     *
     * @return list<mixed>
     */
    private function columnValues(string $column): array
    {
        $values = [];

        foreach ($this->matchingModels() as $model) {
            $values[] = $this->attribute($model, $column);
        }

        return $values;
    }

    /**
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
        if ($this->orders !== [] || $this->randomOrder) {
            yield from $this->yieldOrdered();

            return;
        }

        yield from $this->yieldUnordered();
    }

    /**
     * @return Generator<int, TModel>
     */
    private function yieldOrdered(): Generator
    {
        $models = new Collection($this->matchingModels());

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
                fn (Model $model): mixed => $this->attribute($model, $order['column']),
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
        if (! $this->updatedAtResolved) {
            $model = $this->model();
            $this->updatedAtColumn = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;
            $this->updatedAtResolved = true;
        }

        return $this->updatedAtColumn;
    }

    /**
     * @return ?Collection<int, array{slug: string, mtime: int, data: array<string, mixed>}>
     */
    private function parseFreeRecords(): ?Collection
    {
        if ($this->wheres !== [] || $this->randomOrder) {
            return null;
        }

        $updatedAt = $this->updatedAtColumn();
        $parseFree = array_filter(['slug', $updatedAt]);

        $ordered = array_all(
            $this->orders,
            fn (array $order): bool => in_array($order['column'], $parseFree, true)
        );

        if (! $ordered) {
            return null;
        }

        $records = $this->records();

        if ($this->orders === []) {
            return $records;
        }

        // Must sort identically to applyOrdersAndLimits, which reads the same columns off a hydrated model.
        foreach (array_reverse($this->orders) as $order) {
            $records = $records->sortBy(
                fn (array $record): mixed => $order['column'] === $updatedAt ? $record['mtime'] : $record['slug'],
                SORT_REGULAR,
                $order['direction'] === 'desc'
            );
        }

        return $records->values();
    }

    /**
     * @return Generator<int, TModel>
     */
    private function yieldUnordered(): Generator
    {
        $yielded = 0;
        $skipped = 0;

        foreach ($this->matchingModels() as $model) {
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
     * @return Collection<int, array{slug: string, mtime: int, data: array<string, mixed>}>
     */
    private function records(): Collection
    {
        try {
            $entries = $this->manifest->records($this->adapter, $this->driver, $this->contentPath, $this->nested());
        } catch (ContentPathNotFoundException) {
            throw ContentPathNotFoundException::forPath($this->contentPath, $this->modelClass);
        }

        $records = [];

        foreach ($entries as $slug => $entry) {
            $records[] = ['slug' => (string) $slug, 'mtime' => $entry['mtime'], 'data' => $entry['data']];
        }

        return collect($records);
    }

    /**
     * @return list<string>
     */
    private function scanSlugs(): array
    {
        try {
            return $this->manifest->slugs($this->adapter, $this->driver, $this->contentPath, $this->nested());
        } catch (ContentPathNotFoundException) {
            throw ContentPathNotFoundException::forPath($this->contentPath, $this->modelClass);
        }
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
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    private function hydrate(string $slug, int $mtime, array $data): Model
    {
        $data['slug'] = $slug;

        $column = $this->updatedAtColumn();

        if ($column !== null && $mtime > 0) {
            $data[$column] = $mtime;
        }

        $model = new $this->modelClass;
        $attributes = PaperCasts::fromStorage($model, $data);
        $model->setRawAttributes($attributes, true);
        $model->exists = true;

        return $model;
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    private function eagerLoadRelations(Collection $models): void
    {
        if ($this->with === [] || $models->isEmpty()) {
            return;
        }

        $first = $models->first();

        foreach ($this->with as $name) {
            if (! method_exists($first, $name)) {
                throw new BadMethodCallException(
                    sprintf('Relation %s::%s does not exist.', $first::class, $name)
                );
            }

            $relation = $first->{$name}();

            if (! $relation instanceof PaperRelation) {
                throw new BadMethodCallException(
                    sprintf(
                        'Relation %s::%s must return %s for eager loading.',
                        $first::class,
                        $name,
                        PaperRelation::class,
                    )
                );
            }

            $relation->eagerLoad($models, $name);
        }
    }

    private function matchesWheres(Model $model): bool
    {
        return $this->matches(fn (string $column): mixed => $this->attribute($model, $column), $this->wheres, $model);
    }

    /**
     * @param  array{slug: string, mtime: int, data: array<string, mixed>}  $record
     */
    private function recordMatches(array $record): bool
    {
        $row = ['slug' => $record['slug']] + $record['data'];

        return $this->matches(fn (string $column): mixed => $this->rowValue($row, $column), $this->wheres, null);
    }

    private function attribute(Model $model, string $column): mixed
    {
        if (! str_contains($column, '.') || array_key_exists($column, $model->getAttributes())) {
            return $model->getAttribute($column);
        }

        return data_get($model, $column);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowValue(array $row, string $column): mixed
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        return str_contains($column, '.') ? data_get($row, $column) : null;
    }

    /**
     * @param  Closure(string): mixed  $resolve
     * @param  array<int, array{type: string, boolean: string, column?: string, second?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool, relation?: string, count?: int, constraint?: ?Closure}>  $wheres
     */
    private function matches(Closure $resolve, array $wheres, ?Model $model): bool
    {
        if ($wheres === []) {
            return true;
        }

        $result = true;

        foreach ($wheres as $index => $where) {
            $matches = $this->evaluateWhere($resolve, $where, $model);

            if ($index === 0) {
                $result = $matches;
            } elseif ($where['boolean'] === 'or') {
                $result = $result || $matches;
            } else {
                $result = $result && $matches;
            }
        }

        return $result;
    }

    /**
     * @param  Closure(string): mixed  $resolve
     * @param  array{type: string, boolean: string, column?: string, second?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool, relation?: string, count?: int, constraint?: ?Closure, wheres?: array<int, array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>}>}  $where
     */
    private function evaluateWhere(Closure $resolve, array $where, ?Model $model): bool
    {
        if ($where['type'] === 'group') {
            return $this->matches($resolve, $where['wheres'] ?? [], $model);
        }

        if ($where['type'] === 'has') {
            return $model !== null && $this->evaluateHas($model, $where);
        }

        $column = $where['column'] ?? '';
        $value = $resolve($column);

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
            'date', 'year', 'month', 'day' => $this->evaluateDate($value, $where['type'], $where['operator'] ?? '=', $where['value'] ?? null),
            'column' => $this->evaluateCondition($value, $where['operator'] ?? '=', $resolve($where['second'] ?? '')),
            default => $this->evaluateCondition($value, $where['operator'] ?? '=', $where['value'] ?? null),
        };
    }

    /**
     * @param  array{relation?: string, constraint?: ?Closure, operator?: string, count?: int}  $where
     */
    private function evaluateHas(Model $model, array $where): bool
    {
        $counter = $this->hasCounter($model, $where['relation'] ?? '', $where['constraint'] ?? null);

        return $this->evaluateCondition($counter($model), $where['operator'] ?? '>=', $where['count'] ?? 1);
    }

    /**
     * @return callable(Model): int
     */
    private function hasCounter(Model $model, string $relation, ?Closure $constraint): callable
    {
        $key = $constraint === null ? 'r:'.$relation : 'c:'.$relation.':'.spl_object_id($constraint);

        return $this->hasCounters[$key] ??= $this->buildHasCounter($model, $relation, $constraint);
    }

    /**
     * @return callable(Model): int
     */
    private function buildHasCounter(Model $model, string $relation, ?Closure $constraint): callable
    {
        if (! method_exists($model, $relation)) {
            throw new BadMethodCallException(
                sprintf('Relation %s::%s does not exist.', $model::class, $relation)
            );
        }

        $paperRelation = $model->{$relation}();

        if (! $paperRelation instanceof PaperRelation) {
            throw new BadMethodCallException(
                sprintf('Relation %s::%s must return %s to filter on.', $model::class, $relation, PaperRelation::class)
            );
        }

        return $paperRelation->counter($constraint);
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
        if (count($values) < 2) {
            return false;
        }

        return $value >= $values[0] && $value <= $values[1];
    }
}
