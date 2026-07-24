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
 *
 * @extends PaperRelation<TRelated>
 */
final readonly class BelongsToPaper extends PaperRelation
{
    /**
     * @return ?TRelated
     */
    public function getResults(): ?Model
    {
        $key = $this->keyOf($this->parent, $this->foreignKey);

        if ($key === null) {
            return null;
        }

        return PaperQueryBuilder::forModel($this->relatedClass)->find((string) $key);
    }

    /**
     * @return callable(Model): int
     */
    public function counter(?Closure $constraint): callable
    {
        $relatedKey = new $this->relatedClass()->getKeyName();
        $query = PaperQueryBuilder::forModel($this->relatedClass);

        if ($constraint !== null) {
            $constraint($query);
        }

        $keys = [];

        foreach ($query->pluck($relatedKey) as $key) {
            if (is_string($key) || is_int($key)) {
                $keys[$key] = true;
            }
        }

        return function (Model $parent) use ($keys): int {
            $key = $this->keyOf($parent, $this->foreignKey);

            return $key !== null && isset($keys[$key]) ? 1 : 0;
        };
    }

    /**
     * @param  Collection<int, Model>  $parents
     */
    public function eagerLoad(Collection $parents, string $relationName): void
    {
        $keys = $this->collectKeys($parents, $this->foreignKey);

        if ($keys === []) {
            foreach ($parents as $parent) {
                $parent->setRelation($relationName, null);
            }

            return;
        }

        $relatedKey = new $this->relatedClass()->getKeyName();

        $related = PaperQueryBuilder::forModel($this->relatedClass)
            ->whereIn($relatedKey, $keys)
            ->get();

        $map = $this->indexBy($related, $relatedKey);

        foreach ($parents as $parent) {
            $key = $this->keyOf($parent, $this->foreignKey);
            $parent->setRelation($relationName, $key !== null ? ($map[$key] ?? null) : null);
        }
    }

    /**
     * @param  Collection<int, TRelated>  $models
     * @return array<int|string, TRelated>
     */
    private function indexBy(Collection $models, string $column): array
    {
        $map = [];

        foreach ($models as $model) {
            $key = $model->getAttribute($column);

            if (is_string($key) || is_int($key)) {
                $map[$key] = $model;
            }
        }

        return $map;
    }
}
