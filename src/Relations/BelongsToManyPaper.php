<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Relations;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

/**
 * @template TRelated of Model&PaperModel
 *
 * @extends PaperRelation<TRelated>
 */
final readonly class BelongsToManyPaper extends PaperRelation
{
    /**
     * @return PaperQueryBuilder<TRelated>
     */
    public function query(): PaperQueryBuilder
    {
        $slugs = $this->slugsOf($this->parent);

        return PaperQueryBuilder::forModel($this->relatedClass)->whereIn($this->relatedKeyName(), $slugs);
    }

    /**
     * @return Collection<int, TRelated>
     */
    public function getResults(): Collection
    {
        $related = $this->keyed($this->query()->get());

        return $this->inListOrder($this->parent, $related);
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

        $existing = [];

        foreach ($query->pluck($this->relatedKeyName()) as $key) {
            if (is_string($key) || is_int($key)) {
                $existing[$key] = true;
            }
        }

        return function (Model $parent) use ($existing): int {
            $slugs = $this->slugsOf($parent);

            return count(array_filter($slugs, fn (int|string $slug): bool => isset($existing[$slug])));
        };
    }

    /**
     * @param  Collection<int, Model>  $parents
     * @param  ?Closure(PaperQueryBuilder<TRelated>): mixed  $constraint
     */
    public function eagerLoad(Collection $parents, string $relationName, ?Closure $constraint): void
    {
        $slugs = [];

        foreach ($parents as $parent) {
            foreach ($this->slugsOf($parent) as $slug) {
                $slugs[$slug] = true;
            }
        }

        $related = [];

        if ($slugs !== []) {
            $query = PaperQueryBuilder::forModel($this->relatedClass)
                ->whereIn($this->relatedKeyName(), array_keys($slugs));

            if ($constraint !== null) {
                $constraint($query);
            }

            $related = $this->keyed($query->get());
        }

        foreach ($parents as $parent) {
            $parent->setRelation($relationName, $this->inListOrder($parent, $related));
        }
    }

    /**
     * @return list<int|string>
     */
    private function slugsOf(Model $parent): array
    {
        $value = $parent->getAttribute($this->foreignKey);

        return $this->slugList($value) ?? throw InvalidSlugException::notAList(
            $this->foreignKey,
            $parent::class,
            (string) $this->keyOf($parent, $parent->getKeyName()),
        );
    }

    /**
     * @param  array<int|string, TRelated>  $related
     * @return Collection<int, TRelated>
     */
    private function inListOrder(Model $parent, array $related): Collection
    {
        $ordered = $this->newCollection();

        foreach ($this->slugsOf($parent) as $slug) {
            if (isset($related[$slug])) {
                $ordered->push($related[$slug]);
            }
        }

        return $ordered;
    }
}
