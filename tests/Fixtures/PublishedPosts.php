<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Relations\PaperRelation;

/**
 * @extends PaperRelation<Post>
 */
final readonly class PublishedPosts extends PaperRelation
{
    /**
     * @return PaperQueryBuilder<Post>
     */
    public function query(): PaperQueryBuilder
    {
        $key = $this->keyOf($this->parent, $this->parent->getKeyName());

        return PaperQueryBuilder::forModel($this->relatedClass)
            ->whereIn($this->foreignKey, $key === null ? [] : [$key])
            ->where('published', true);
    }

    /**
     * @return Collection<int, Post>
     */
    public function getResults(): Collection
    {
        return $this->query()->get();
    }

    /**
     * @param  Collection<int, Model>  $parents
     * @param  ?Closure(PaperQueryBuilder<Post>): mixed  $constraint
     */
    public function eagerLoad(Collection $parents, string $relationName, ?Closure $constraint): void
    {
        foreach ($parents as $parent) {
            $parent->setRelation($relationName, $this->forParent($parent)->getResults());
        }
    }

    /**
     * @return callable(Model): int
     */
    public function counter(?Closure $constraint): callable
    {
        return fn (Model $parent): int => $this->forParent($parent)->query()->count();
    }

    private function forParent(Model $parent): self
    {
        return new self($parent, $this->relatedClass, $this->foreignKey);
    }
}
