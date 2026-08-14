<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
final class SortedPost extends PaperModel
{
    protected static function booted(): void
    {
        self::addGlobalScope('sorted', fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->orderByDesc('order'));
    }
}
