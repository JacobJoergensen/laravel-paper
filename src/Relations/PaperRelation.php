<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Relations;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;

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
     * @param  Collection<int, Model>  $parents
     */
    abstract public function eagerLoad(Collection $parents, string $relationName): void;

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
}
