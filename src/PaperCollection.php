<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedCollectionMethodException;

/**
 * @template TModel of Model&PaperModel
 *
 * @extends Collection<int, TModel>
 *
 * @phpstan-consistent-constructor
 */
class PaperCollection extends Collection
{
    /**
     * @param  array<int|string, string|Closure>|string  $relations
     */
    public function load($relations, string ...$more): static
    {
        $first = $this->first();

        if ($first === null) {
            return $this;
        }

        $names = is_string($relations) ? [$relations, ...$more] : $relations;

        PaperQueryBuilder::forModel($first::class)->with($names)->eagerLoadRelations($this->all());

        return $this;
    }

    /**
     * Paper has no nested relations, so there is no dot path to walk like Eloquent does.
     *
     * @param  array<int|string, string|Closure>|string  $relations
     */
    public function loadMissing($relations, string ...$more): static
    {
        $names = is_string($relations) ? [$relations, ...$more] : $relations;

        foreach ($names as $key => $relation) {
            $name = is_int($key) ? $relation : $key;

            if (! is_string($name)) {
                continue;
            }

            $missing = $this->reject(fn (Model $model): bool => $model->relationLoaded($name));

            if ($missing->isNotEmpty()) {
                $missing->load(is_int($key) ? [$name] : [$name => $relation]);
            }
        }

        return $this;
    }

    /**
     * @param  array<array-key, mixed>|string  $relations
     */
    public function loadAggregate($relations, $column, $function = null): never
    {
        throw UnsupportedCollectionMethodException::forMethod('loadAggregate');
    }

    /**
     * @param  array<array-key, mixed>|string  $relations
     */
    public function loadMorph($relation, $relations): never
    {
        throw UnsupportedCollectionMethodException::forMethod('loadMorph');
    }

    /**
     * @param  array<array-key, mixed>|string  $relations
     */
    public function loadMorphCount($relation, $relations): never
    {
        throw UnsupportedCollectionMethodException::forMethod('loadMorphCount');
    }

    /**
     * @param  array<int|string, string|Closure>|string  $with
     */
    public function fresh($with = []): static
    {
        $first = $this->first();

        if ($first === null) {
            return $this;
        }

        $names = is_string($with) ? [$with] : $with;
        $keyName = $first->getKeyName();

        $refreshed = PaperQueryBuilder::forModel($first::class)
            ->with($names)
            ->whereIn($keyName, $this->modelKeys())
            ->get()
            ->keyBy($keyName);

        $surviving = [];

        foreach ($this as $model) {
            $key = $model->getAttribute($keyName);
            $fresh = is_string($key) || is_int($key) ? $refreshed->get($key) : null;

            if ($fresh !== null) {
                $surviving[] = $fresh;
            }
        }

        return new static($surviving);
    }

    public function toQuery(): never
    {
        throw UnsupportedCollectionMethodException::forMethod('toQuery');
    }
}
