<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;

final class PaperQueryBuilder
{
    /** @var array<int, array{column: string, operator: string, value: mixed}> */
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

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null && ! is_string($operator)) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'column' => $column,
            'operator' => is_string($operator) ? $operator : '=',
            'value' => $value,
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction),
        ];

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

    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
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

    private function matchesWheres(Model $model): bool
    {
        foreach ($this->wheres as $where) {
            $value = $model->getAttribute($where['column']);

            if (! $this->evaluateCondition($value, $where['operator'], $where['value'])) {
                return false;
            }
        }

        return true;
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
}
