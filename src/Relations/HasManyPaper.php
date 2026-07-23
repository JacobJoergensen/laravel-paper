<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

/**
 * @template TRelated of Model&PaperModel
 *
 * @extends PaperRelation<TRelated>
 */
final readonly class HasManyPaper extends PaperRelation
{
    /**
     * @return PaperQueryBuilder<TRelated>
     */
    public function query(): PaperQueryBuilder
    {
        $key = $this->keyOf($this->parent, $this->parent->getKeyName());
        $keys = $key === null ? [] : [$key];

        return PaperQueryBuilder::forModel($this->relatedClass)->whereIn($this->foreignKey, $keys);
    }

    /**
     * @return Collection<int, TRelated>
     */
    public function getResults(): Collection
    {
        return $this->query()->get();
    }

    /**
     * @param  Collection<int, Model>  $parents
     */
    public function eagerLoad(Collection $parents, string $relationName): void
    {
        $first = $parents->first();

        if ($first === null) {
            return;
        }

        $parentKeyName = $first->getKeyName();
        $parentKeys = $this->collectKeys($parents, $parentKeyName);

        if ($parentKeys === []) {
            foreach ($parents as $parent) {
                $parent->setRelation($relationName, new Collection);
            }

            return;
        }

        $related = PaperQueryBuilder::forModel($this->relatedClass)
            ->whereIn($this->foreignKey, $parentKeys)
            ->get();

        $grouped = $this->groupBy($related, $this->foreignKey);

        foreach ($parents as $parent) {
            $key = $this->keyOf($parent, $parentKeyName);
            $parent->setRelation($relationName, $key !== null ? ($grouped[$key] ?? new Collection) : new Collection);
        }
    }

    /**
     * @param  Collection<int, TRelated>  $models
     * @return array<int|string, Collection<int, TRelated>>
     */
    private function groupBy(Collection $models, string $column): array
    {
        $groups = [];

        foreach ($models as $model) {
            $key = $model->getAttribute($column);

            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $groups[$key] ??= new Collection;
            $groups[$key]->push($model);
        }

        return $groups;
    }
}
