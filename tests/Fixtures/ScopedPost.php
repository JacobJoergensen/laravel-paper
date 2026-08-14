<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
#[ScopedBy(PublishedScope::class)]
final class ScopedPost extends PaperModel
{
    protected static function booted(): void
    {
        self::addGlobalScope('later', fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('order', '>', 1));
    }
}
