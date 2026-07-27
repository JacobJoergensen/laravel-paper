<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
#[ScopedBy(PublishedScope::class)]
final class ScopedPost extends Model implements PaperModel
{
    use Paper;

    protected static function booted(): void
    {
        self::addGlobalScope('later', fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('order', '>', 1));
    }
}
