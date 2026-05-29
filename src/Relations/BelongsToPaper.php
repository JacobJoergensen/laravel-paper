<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final readonly class BelongsToPaper extends PaperRelation
{
    public function getResults(): ?Model
    {
        $key = $this->keyOf($this->parent, $this->foreignKey);

        if ($key === null) {
            return null;
        }

        return PaperQueryBuilder::forModel($this->relatedClass)->find((string) $key);
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
     * @param  Collection<int, Model>  $models
     * @return array<int|string, Model>
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
