<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract readonly class PaperRelation
{
    /**
     * @param  class-string<Model>  $relatedClass
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
     * @param  Collection<int, Model>  $parents
     * @return list<int|string>
     */
    protected function collectKeys(Collection $parents, string $column): array
    {
        $keys = [];

        foreach ($parents as $parent) {
            $key = $this->keyOf($parent, $column);

            if ($key !== null && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    protected function keyOf(Model $model, string $column): null|int|string
    {
        $value = $model->getAttribute($column);

        return is_string($value) || is_int($value) ? $value : null;
    }
}
