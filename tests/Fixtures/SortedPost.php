<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
final class SortedPost extends Model implements PaperModel
{
    use Paper;

    protected static function booted(): void
    {
        self::addGlobalScope('sorted', fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->orderByDesc('order'));
    }
}
