<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Disk;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Attributes\Timestamps;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('json')]
#[ContentPath('manuals', nested: true)]
#[Disk('paper')]
#[Timestamps]
class Manual extends PaperModel {}
