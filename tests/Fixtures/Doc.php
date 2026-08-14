<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('markdown')]
#[ContentPath('tests/content/docs', nested: true)]
final class Doc extends PaperModel
{
    /** @var list<string> */
    protected $guarded = [];
}
