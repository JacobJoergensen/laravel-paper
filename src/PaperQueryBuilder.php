<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use BadMethodCallException;
use Closure;
use Generator;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use JacobJoergensen\LaravelPaper\Attributes\Disk;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Attributes\Timestamps;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Relations\PaperRelation;
use JacobJoergensen\LaravelPaper\StorageAdapters\DiskAdapter;
use JacobJoergensen\LaravelPaper\StorageAdapters\LocalAdapter;
use ReflectionClass;
use ReflectionMethod;

final class PaperQueryBuilder
{
    /** @var list<array{type: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool, wheres?: list<array<string, mixed>>, boolean: string}> */
    private array $wheres = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limitValue = null;

    private int $offsetValue = 0;

    private bool $randomOrder = false;

    /** @var list<string> */
    private array $with = [];

    /** @var array<class-string<Model>, DriverContract> */
    private static array $driverCache = [];

    /** @var array<class-string<Model>, bool> */
    private static array $usesDiskCache = [];

    /** @var array<class-string<Model>, StorageAdapterContract> */
    private static array $adapterCache = [];

    /** @var array<class-string<Model>, bool> */
    private static array $timestampsCache = [];

    public function __construct(
        private readonly StorageAdapterContract $adapter,
        private readonly DriverContract $driver,
        private readonly PaperManifest $manifest,
        private readonly string $contentPath,
        private readonly string $modelClass,
    ) {}

    /**
     * @param  class-string<Model>  $modelClass
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
     * @param  class-string<Model>  $modelClass
     * @return array{driver: DriverContract, adapter: StorageAdapterContract, usesDisk: bool}
     */
    public static function resolveFor(string $modelClass): array
    {
        if (! isset(self::$driverCache[$modelClass])) {
            $reflection = new ReflectionClass($modelClass);

            $driverAttribute = $reflection->getAttributes(Driver::class)[0] ?? null;
            $diskAttribute = $reflection->getAttributes(Disk::class)[0] ?? null;

            $driverName = $driverAttribute?->newInstance()->name ?? 'markdown';
            $diskName = $diskAttribute?->newInstance()->name;

            self::$driverCache[$modelClass] = app(DriverRegistry::class)->resolve($driverName);
            self::$timestampsCache[$modelClass] = $reflection->getAttributes(Timestamps::class) !== [];

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
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function contentPathFor(string $modelClass): string
    {
        $usesDisk = self::resolveFor($modelClass)['usesDisk'];

        $model = new $modelClass;
        $resolved = (new ReflectionMethod($model, 'getContentPath'))->invoke($model);
        $path = is_string($resolved) ? $resolved : 'content';

        return $usesDisk ? $path : base_path($path);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function usesTimestamps(string $modelClass): bool
    {
        self::resolveFor($modelClass);

        return self::$timestampsCache[$modelClass];
    }

    /**
     * @param  ?class-string<Model>  $modelClass
     */
    public static function forgetCache(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            self::$driverCache = [];
            self::$usesDiskCache = [];
            self::$adapterCache = [];
            self::$timestampsCache = [];

            return;
        }

        unset(
            self::$driverCache[$modelClass],
            self::$usesDiskCache[$modelClass],
            self::$adapterCache[$modelClass],
            self::$timestampsCache[$modelClass],
        );
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

    public function findOr(string $slug, Closure $callback): mixed
    {
        return $this->find($slug) ?? $callback();
    }

    /**
     * @param  array<int, scalar>  $ids
     * @return Collection<int, Model>
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

        /** @var Model $instance */
        $instance = new $this->modelClass;

        $collection = $instance->newCollection($models);

        $this->eagerLoadRelations($collection);

        $collection->each($this->fireRetrieved(...));

        return $collection;
    }

    private function locate(string $slug): ?Model
    {
        self::guardSlug($slug);

        foreach ($this->driver->extensions() as $ext) {
            $filepath = $this->contentPath.'/'.$slug.'.'.$ext;
            $contents = $this->adapter->read($filepath);

            if ($contents === null) {
                continue;
            }

            try {
                $data = $this->driver->parse($contents);
            } catch (FileParseException $e) {
                throw FileParseException::inFile($filepath, $e);
            }

            return $this->hydrate($slug, $this->adapter->lastModified($filepath) ?? 0, $data);
        }

        return null;
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function where(callable|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        if (is_callable($column)) {
            return $this->whereGroup($column, $boolean);
        }

        if ($value === null && ! in_array($operator, ['=', '==', '===', '!=', '<>', '!==', '>', '>=', '<', '<=', 'like'], true)) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => is_string($operator) ? $operator : '=',
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function orWhere(callable|string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'or');
    }

    private function whereGroup(callable $callback, string $boolean): self
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

    /**
     * @param  array<int, scalar>  $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and'): self
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
    public function orWhereIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * @param  array<int, scalar>  $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
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
    public function orWhereNotIn(string $column, array $values): self
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * Matches rows where the array field includes the given value.
     *
     * @param  scalar  $value
     */
    public function whereContains(string $column, mixed $value, string $boolean = 'and'): self
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
    public function orWhereContains(string $column, mixed $value): self
    {
        return $this->whereContains($column, $value, 'or');
    }

    public function whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'and'): self
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

    public function orWhereLike(string $column, string $value, bool $caseSensitive = false): self
    {
        return $this->whereLike($column, $value, $caseSensitive, 'or');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
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
    public function orWhereAny(array $columns, mixed $operator = null, mixed $value = null): self
    {
        return $this->whereAny($columns, $operator, $value, 'or');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
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
    public function orWhereAll(array $columns, mixed $operator = null, mixed $value = null): self
    {
        return $this->whereAll($columns, $operator, $value, 'or');
    }

    public function whereNull(string $column, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'or');
    }

    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'type' => 'notNull',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'or');
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and'): self
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
    public function orWhereBetween(string $column, array $values): self
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * @param  array{0: scalar, 1: scalar}  $values
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): self
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
    public function orWhereNotBetween(string $column, array $values): self
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction),
        ];

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function latest(string $column = 'updated_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'updated_at'): self
    {
        return $this->orderBy($column);
    }

    public function inRandomOrder(): self
    {
        $this->randomOrder = true;

        return $this;
    }

    public function limit(int $value): self
    {
        $this->limitValue = $value;

        return $this;
    }

    public function take(int $value): self
    {
        return $this->limit($value);
    }

    public function offset(int $value): self
    {
        $this->offsetValue = $value;

        return $this;
    }

    public function skip(int $value): self
    {
        return $this->offset($value);
    }

    /**
     * @param  array<int, string>|string  $relations
     */
    public function with(array|string $relations): self
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

    public function first(): ?Model
    {
        if ($this->orders === [] && $this->with === []) {
            return $this->lazy()->first();
        }

        return $this->limit(1)->get()->first();
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function firstWhere(callable|string $column, mixed $operator = null, mixed $value = null): ?Model
    {
        return $this->where($column, $operator, $value)->first();
    }

    public function value(string $column): mixed
    {
        return $this->first()?->getAttribute($column);
    }

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

    public function firstOr(Closure $callback): mixed
    {
        return $this->first() ?? $callback();
    }

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
            return count($this->scanSlugs());
        }

        return $this->lazyModels()->count();
    }

    public function exists(): bool
    {
        if ($this->wheres === []) {
            return $this->scanSlugs() !== [];
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
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $page ??= Paginator::resolveCurrentPage();

        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        try {
            $this->limitValue = null;
            $this->offsetValue = 0;

            $all = $this->getModels();
            $total = $all->count();
            $items = $all->slice(($page - 1) * $perPage)->take($perPage)->values();

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
     * @return Paginator<int, Model>
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
            $items = $this->lazyModels()->skip($offset)->take($perPage + 1)->collect();

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
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        $models = $this->getModels();

        $this->eagerLoadRelations($models);

        $models->each($this->fireRetrieved(...));

        return $models;
    }

    /**
     * @return Collection<int, Model>
     */
    private function getModels(): Collection
    {
        $models = $this->records()
            ->map(fn (array $record): Model => $this->hydrate($record['slug'], $record['mtime'], $record['data']))
            ->filter(fn (Model $model): bool => $this->matchesWheres($model));

        $results = $this->applyOrdersAndLimits($models);

        /** @var Model $instance */
        $instance = new $this->modelClass;

        return $instance->newCollection($results->all());
    }

    /**
     * Ignores orders, limits and offsets so aggregates span every matching record.
     *
     * @return list<mixed>
     */
    private function columnValues(string $column): array
    {
        $values = [];

        foreach ($this->records() as $record) {
            $model = $this->hydrate($record['slug'], $record['mtime'], $record['data']);

            if ($this->matchesWheres($model)) {
                $values[] = $model->getAttribute($column);
            }
        }

        return $values;
    }

    /**
     * Builds models lazily from the manifest, one at a time.
     *
     * @return LazyCollection<int, Model>
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
     * @param  callable(Collection<int, Model>, int): mixed  $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;

        foreach ($this->lazy()->chunk($count) as $chunk) {
            /** @var Model $instance */
            $instance = new $this->modelClass;
            $models = $instance->newCollection($chunk->all());

            if ($callback($models, $page) === false) {
                return false;
            }

            $page++;
        }

        return true;
    }

    /**
     * @param  callable(Model, array-key): mixed  $callback
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
     * @return LazyCollection<int, Model>
     */
    private function lazyModels(): LazyCollection
    {
        return new LazyCollection($this->yieldModels(...));
    }

    /**
     * @param  (callable(self, mixed): mixed)|null  $callback
     * @param  (callable(self, mixed): mixed)|null  $default
     */
    public function when(mixed $value, ?callable $callback = null, ?callable $default = null): self
    {
        $active = $value ? $callback : $default;

        if ($active !== null) {
            $active($this, $value);
        }

        return $this;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): self
    {
        $scope = $this->resolveScope($method);

        if ($scope === null) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', self::class, $method)
            );
        }

        $model = new $this->modelClass;
        $scope->invoke($model, $this, ...$parameters);

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
     * @return Generator<int, Model, mixed, void>
     */
    private function yieldModels(): Generator
    {
        $records = $this->records();

        if ($this->orders !== [] || $this->randomOrder) {
            yield from $this->yieldOrdered($records);

            return;
        }

        yield from $this->yieldUnordered($records);
    }

    /**
     * @param  Collection<int, array{slug: string, mtime: int, data: array<string, mixed>}>  $records
     * @return Generator<int, Model>
     */
    private function yieldOrdered(Collection $records): Generator
    {
        $models = $records
            ->map(fn (array $record): Model => $this->hydrate($record['slug'], $record['mtime'], $record['data']))
            ->filter(fn (Model $model): bool => $this->matchesWheres($model));

        foreach ($this->applyOrdersAndLimits($models) as $model) {
            yield $model;
        }
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return Collection<int, Model>
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

    /**
     * @param  Collection<int, array{slug: string, mtime: int, data: array<string, mixed>}>  $records
     * @return Generator<int, Model>
     */
    private function yieldUnordered(Collection $records): Generator
    {
        $yielded = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $model = $this->hydrate($record['slug'], $record['mtime'], $record['data']);

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
     * @return Collection<int, array{slug: string, mtime: int, data: array<string, mixed>}>
     */
    private function records(): Collection
    {
        try {
            $entries = $this->manifest->records($this->adapter, $this->driver, $this->contentPath);
        } catch (ContentPathNotFoundException) {
            throw ContentPathNotFoundException::forPath($this->contentPath, $this->modelClass);
        }

        $records = [];

        foreach ($entries as $slug => $entry) {
            $records[] = ['slug' => $slug, 'mtime' => $entry['mtime'], 'data' => $entry['data']];
        }

        return collect($records);
    }

    /**
     * @return list<string>
     */
    private function scanSlugs(): array
    {
        try {
            return $this->manifest->slugs($this->adapter, $this->driver, $this->contentPath);
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
     */
    private function hydrate(string $slug, int $mtime, array $data): Model
    {
        $data['slug'] = $slug;

        /** @var Model $model */
        $model = new $this->modelClass;

        if ($model->usesTimestamps() && $mtime > 0) {
            $column = $model->getUpdatedAtColumn();

            if ($column !== null) {
                $data[$column] = $mtime;
            }
        }

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

    /**
     * @param  ?array<int, array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool}>  $wheres
     */
    private function matchesWheres(Model $model, ?array $wheres = null): bool
    {
        $wheres ??= $this->wheres;

        if ($wheres === []) {
            return true;
        }

        $result = true;

        foreach ($wheres as $index => $where) {
            /** @var array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>} $where */
            $matches = $this->evaluateWhere($model, $where);

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
     * @param  array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, caseSensitive?: bool, wheres?: array<int, array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>}>}  $where
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
            'null' => $value === null,
            'notNull' => $value !== null,
            'between' => $value !== null && $this->evaluateBetween($value, $where['values'] ?? []),
            'notBetween' => $value !== null && ! $this->evaluateBetween($value, $where['values'] ?? []),
            default => $this->evaluateCondition($value, $where['operator'] ?? '=', $where['value'] ?? null),
        };
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
