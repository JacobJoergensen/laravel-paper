<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Contracts\ScopeContract;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

/**
 * @implements ScopeContract<ScopedPost>
 */
final class PublishedScope implements ScopeContract
{
    public function apply(PaperQueryBuilder $query, Model $model): void
    {
        $query->where('published', true);
    }
}
