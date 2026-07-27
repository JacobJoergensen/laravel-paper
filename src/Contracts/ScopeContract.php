<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

/**
 * @template TModel of Model&PaperModel
 */
interface ScopeContract
{
    /**
     * @param  PaperQueryBuilder<TModel>  $query
     * @param  TModel  $model
     */
    public function apply(PaperQueryBuilder $query, Model $model): void;
}
