<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Attributes\Timestamps;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
#[Timestamps]
final class DatedPost extends PaperModel
{
    const UPDATED_AT = 'date';

    /** @var list<string> */
    protected $guarded = [];
}
