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
final readonly class HasManyPaperByList extends PaperRelation
{
    /**
     * @return PaperQueryBuilder<TRelated>
     */
    public function query(): PaperQueryBuilder
    {
        $key = $this->keyOf($this->parent, $this->parent->getKeyName());
        $query = PaperQueryBuilder::forModel($this->relatedClass);

        return $key === null
            ? $query->whereIn($this->foreignKey, [])
            : $query->whereContains($this->foreignKey, $key);
    }

    /**
     * @return Collection<int, TRelated>
     */
    public function getResults(): Collection
    {
        return $this->query()->get();
    }

    /**
     * @return callable(Model): int
     */
    public function counter(?Closure $constraint): callable
    {
        $query = PaperQueryBuilder::forModel($this->relatedClass);

        if ($constraint !== null) {
            $constraint($query);
        }

        $counts = [];

        foreach ($query->pluck($this->foreignKey) as $list) {
            foreach ($this->slugList($list) ?? [] as $slug) {
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        return function (Model $parent) use ($counts): int {
            $key = $this->keyOf($parent, $parent->getKeyName());

            return $key === null ? 0 : ($counts[$key] ?? 0);
        };
    }

    /**
     * @param  Collection<int, Model>  $parents
     * @param  ?Closure(PaperQueryBuilder<TRelated>): mixed  $constraint
     */
    public function eagerLoad(Collection $parents, string $relationName, ?Closure $constraint): void
    {
        $parentKeyName = $this->parent->getKeyName();
        $parentKeys = $this->collectKeys($parents, $parentKeyName);
        $grouped = [];

        if ($parentKeys !== []) {
            $listingAny = function (PaperQueryBuilder $query) use ($parentKeys): void {
                foreach ($parentKeys as $key) {
                    $query->orWhereContains($this->foreignKey, $key);
                }
            };

            $query = PaperQueryBuilder::forModel($this->relatedClass)->where($listingAny);

            if ($constraint !== null) {
                $constraint($query);
            }

            foreach ($query->get() as $model) {
                $list = $model->getAttribute($this->foreignKey);

                foreach ($this->slugList($list) ?? [] as $slug) {
                    $grouped[$slug] ??= $this->newCollection();
                    $grouped[$slug]->push($model);
                }
            }
        }

        foreach ($parents as $parent) {
            $key = $this->keyOf($parent, $parentKeyName);
            $related = $key === null ? null : ($grouped[$key] ?? null);

            $parent->setRelation($relationName, $related ?? $this->newCollection());
        }
    }
}
