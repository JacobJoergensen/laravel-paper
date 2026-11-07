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
    public function load(mixed $relations, string ...$more): static
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
     * @param  array<int|string, string|Closure>|string  $relations
     */
    public function loadMissing(mixed $relations, string ...$more): static
    {
        $names = is_string($relations) ? [$relations, ...$more] : $relations;

        foreach ($names as $key => $relation) {
            $name = is_int($key) ? $relation : $key;

            if (! is_string($name)) {
                continue;
            }

            $segments = explode('.', $name, 2);
            $root = $segments[0];
            $rest = $segments[1] ?? null;

            [$loaded, $missing] = $this->partition(fn (Model $model): bool => $model->relationLoaded($root));

            if ($missing->isNotEmpty()) {
                $wanted = is_int($key) ? [$name] : [$name => $relation];

                $missing->load($wanted);
            }

            if ($rest === null) {
                continue;
            }

            $children = [];

            foreach ($loaded as $model) {
                $related = $model->getRelation($root);
                $models = $related instanceof Collection ? $related->all() : [$related];

                foreach ($models as $child) {
                    if ($child instanceof Model) {
                        $children[spl_object_id($child)] = $child;
                    }
                }
            }

            $first = reset($children);

            if ($first !== false) {
                $below = is_int($key) ? [$rest] : [$rest => $relation];
                $childCollection = $first->newCollection(array_values($children));

                $childCollection->loadMissing($below);
            }
        }

        return $this;
    }

    /**
     * @param  array<array-key, mixed>|string  $relations
     */
    public function loadAggregate(mixed $relations, mixed $column, mixed $function = null): never
    {
        throw UnsupportedCollectionMethodException::forMethod('loadAggregate');
    }

    /**
     * @param  array<array-key, mixed>|string  $relations
     */
    public function loadMorph(mixed $relation, mixed $relations): never
    {
        throw UnsupportedCollectionMethodException::forMethod('loadMorph');
    }

    /**
     * @param  array<array-key, mixed>|string  $relations
     */
    public function loadMorphCount(mixed $relation, mixed $relations): never
    {
        throw UnsupportedCollectionMethodException::forMethod('loadMorphCount');
    }

    /**
     * @param  array<int|string, string|Closure>|string  $with
     */
    public function fresh(mixed $with = [], string ...$more): static
    {
        $first = $this->first();

        if ($first === null) {
            return $this;
        }

        $names = is_string($with) ? [$with, ...$more] : $with;
        $keyName = $first->getKeyName();
        $storedKeys = [];

        foreach ($this as $model) {
            $key = $model->getRawOriginal($keyName) ?? $model->getAttribute($keyName);

            if ($model->exists && (is_string($key) || is_int($key))) {
                $storedKeys[] = $key;
            }
        }

        $refreshed = PaperQueryBuilder::forModel($first::class)
            ->withoutGlobalScopes()
            ->with($names)
            ->whereIn($keyName, $storedKeys)
            ->get()
            ->keyBy($keyName);

        /** @var list<TModel> $surviving */
        $surviving = [];

        foreach ($storedKeys as $key) {
            $fresh = $refreshed->get($key);

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
