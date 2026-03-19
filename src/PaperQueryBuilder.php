<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;

final class PaperQueryBuilder
{
    /** @var list<array{type: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, wheres?: list<array<string, mixed>>, boolean: string}> */
    private array $wheres = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limitValue = null;

    private int $offsetValue = 0;

    public function __construct(
        private readonly Filesystem $files,
        private readonly DriverContract $driver,
        private readonly CacheContract $cache,
        private readonly string $contentPath,
        private readonly string $modelClass,
    ) {}

    public function find(string $slug): ?Model
    {
        foreach ($this->driver->extensions() as $ext) {
            $filepath = $this->contentPath.'/'.$slug.'.'.$ext;

            if ($this->files->exists($filepath)) {
                return $this->fileToModel($filepath);
            }
        }

        return null;
    }

    /**
     * @param  ?scalar  $operator
     * @param  ?scalar  $value
     */
    public function where(string|callable $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
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
    public function orWhere(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'or');
    }

    private function whereGroup(callable $callback, string $boolean): self
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

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column);
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

    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
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

    public function count(): int
    {
        return $this->get()->count();
    }

    /**
     * @return Collection<int, mixed>
     */
    public function pluck(string $column): Collection
    {
        return $this->get()->pluck($column);
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $page ??= request()->integer('page', 1);

        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        try {
            $this->limitValue = null;
            $this->offsetValue = 0;

            $all = $this->get();
            $total = $all->count();
            $items = $all->slice(($page - 1) * $perPage)->take($perPage)->values();

            return new LengthAwarePaginator($items, $total, $perPage, $page, [
                'path' => request()->url(),
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
        $models = $this->scanFiles()
            ->map(fn (string $filepath): Model => $this->fileToModel($filepath))
            ->filter(fn (Model $model): bool => $this->matchesWheres($model));

        foreach ($this->orders as $order) {
            $models = $models->sortBy(
                fn (Model $model): mixed => $model->getAttribute($order['column']),
                SORT_REGULAR,
                $order['direction'] === 'desc'
            );
        }

        $models = $models->values();

        if ($this->offsetValue > 0) {
            $models = $models->slice($this->offsetValue);
        }

        if ($this->limitValue !== null) {
            $models = $models->take($this->limitValue);
        }

        return $models->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function scanFiles(): Collection
    {
        if (! $this->files->isDirectory($this->contentPath)) {
            throw ContentPathNotFoundException::forPath($this->contentPath, $this->modelClass);
        }

        $extensions = $this->driver->extensions();
        $pattern = '*.{'.implode(',', $extensions).'}';

        /** @var Collection<int, string> */
        return collect($this->files->glob($this->contentPath.'/'.$pattern, GLOB_BRACE) ?: []);
    }

    private function fileToModel(string $filepath): Model
    {
        $data = $this->loadFileData($filepath);
        $slug = pathinfo($filepath, PATHINFO_FILENAME);

        $data['slug'] = $slug;

        /** @var Model $model */
        $model = new $this->modelClass;
        $model->setRawAttributes($data, true);
        $model->exists = true;

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFileData(string $filepath): array
    {
        if (! $this->cache->isStale($filepath)) {
            $cached = $this->cache->get($filepath);

            if ($cached !== null) {
                return $cached;
            }
        }

        $data = $this->driver->parse($filepath);
        $mtime = (int) filemtime($filepath);

        $this->cache->set($filepath, $data, $mtime);

        return $data;
    }

    /**
     * @param  ?array<int, array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>}>  $wheres
     */
    private function matchesWheres(Model $model, ?array $wheres = null): bool
    {
        $wheres ??= $this->wheres;

        if (empty($wheres)) {
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
     * @param  array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>, wheres?: array<int, array{type: string, boolean: string, column?: string, operator?: string, value?: ?scalar, values?: array<int, scalar>}>}  $where
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
            'in' => in_array($value, $where['values'] ?? [], true),
            'notIn' => ! in_array($value, $where['values'] ?? [], true),
            'contains' => is_array($value) && in_array($where['value'] ?? null, $value, true),
            'null' => $value === null,
            'notNull' => $value !== null,
            'between' => $this->evaluateBetween($value, $where['values'] ?? []),
            'notBetween' => ! $this->evaluateBetween($value, $where['values'] ?? []),
            default => $this->evaluateCondition($value, $where['operator'] ?? '=', $where['value'] ?? null),
        };
    }

    private function evaluateCondition(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=', '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=', '<>' => $actual != $expected,
            '!==' => $actual !== $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'like' => is_string($actual) && is_string($expected) && $this->evaluateLike($actual, $expected),
            default => false,
        };
    }

    private function evaluateLike(string $actual, string $pattern): bool
    {
        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';

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
