<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Disk;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('markdown')]
#[ContentPath('articles')]
#[Disk('paper')]
final class Article extends PaperModel
{
    /** @var list<string> */
    protected $guarded = [];
}
