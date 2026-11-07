<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Relations;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

/**
 * @template TRelated of Model&PaperModel
 */
abstract readonly class PaperRelation
{
    /**
     * @param  class-string<TRelated>  $relatedClass
     */
    public function __construct(
        public Model $parent,
        public string $relatedClass,
        public string $foreignKey,
    ) {}

    /**
     * @return PaperQueryBuilder<TRelated>
     */
    abstract public function query(): PaperQueryBuilder;

    /**
     * @return Collection<int, TRelated>|TRelated|null
     */
    abstract public function getResults(): null|Collection|Model;

    /**
     * @param  Collection<int, Model>  $parents
     * @param  ?Closure(PaperQueryBuilder<TRelated>): mixed  $constraint
     */
    abstract public function eagerLoad(Collection $parents, string $relationName, ?Closure $constraint): void;

    /**
     * @return callable(Model): int
     */
    abstract public function counter(?Closure $constraint): callable;

    /**
     * @param  Collection<int, Model>  $parents
     * @return list<int|string>
     */
    protected function collectKeys(Collection $parents, string $column): array
    {
        $keys = [];

        foreach ($parents as $parent) {
            $key = $this->keyOf($parent, $column);

            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    protected function keyOf(Model $model, string $column): null|int|string
    {
        $value = $model->getAttribute($column);

        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * @return ?list<int|string>
     */
    protected function slugList(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $slugs = [];

        foreach ($value as $slug) {
            if (! is_string($slug) && ! is_int($slug)) {
                return null;
            }

            $slugs[$slug] = true;
        }

        return array_keys($slugs);
    }

    /**
     * @param  Collection<int, TRelated>  $models
     * @return array<int|string, TRelated>
     */
    protected function keyed(Collection $models): array
    {
        $relatedKey = $this->relatedKeyName();
        $keyed = [];

        foreach ($models as $model) {
            $key = $this->keyOf($model, $relatedKey);

            if ($key !== null) {
                $keyed[$key] = $model;
            }
        }

        return $keyed;
    }

    protected function relatedKeyName(): string
    {
        $relatedClass = $this->relatedClass;

        return new $relatedClass()->getKeyName();
    }

    /**
     * @return Collection<int, TRelated>
     */
    protected function newCollection(): Collection
    {
        $relatedClass = $this->relatedClass;

        return new $relatedClass()->newCollection();
    }
}
