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
     * @return PaperQueryBuilder<TRelated>
     */
    public function query(): PaperQueryBuilder
    {
        $key = $this->keyOf($this->parent, $this->foreignKey);
        $keys = $key === null ? [] : [$key];

        return PaperQueryBuilder::forModel($this->relatedClass)->whereIn($this->relatedKeyName(), $keys);
    }

    /**
     * @return ?TRelated
     */
    public function getResults(): ?Model
    {
        return $this->query()->first();
    }

    /**
     * @return callable(Model): int
     */
    public function counter(?Closure $constraint): callable
    {
        $relatedKey = $this->relatedKeyName();
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
     * @param  ?Closure(PaperQueryBuilder<TRelated>): mixed  $constraint
     */
    public function eagerLoad(Collection $parents, string $relationName, ?Closure $constraint): void
    {
        $keys = $this->collectKeys($parents, $this->foreignKey);

        if ($keys === []) {
            foreach ($parents as $parent) {
                $parent->setRelation($relationName, null);
            }

            return;
        }

        $relatedKey = $this->relatedKeyName();

        $query = PaperQueryBuilder::forModel($this->relatedClass)->whereIn($relatedKey, $keys);

        if ($constraint !== null) {
            $constraint($query);
        }

        $byKey = [];

        foreach ($query->get() as $model) {
            $key = $this->keyOf($model, $relatedKey);

            if ($key !== null) {
                $byKey[$key] = $model;
            }
        }

        foreach ($parents as $parent) {
            $key = $this->keyOf($parent, $this->foreignKey);
            $related = $key === null ? null : ($byKey[$key] ?? null);

            $parent->setRelation($relationName, $related);
        }
    }

    private function relatedKeyName(): string
    {
        $relatedClass = $this->relatedClass;

        return new $relatedClass()->getKeyName();
    }
}
