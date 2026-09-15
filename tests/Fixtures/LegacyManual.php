<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
final class LegacyManual extends Manual {}
