<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Paper;

/**
 * Stays on the trait and interface so that path keeps coverage.
 */
#[Driver('markdown')]
#[ContentPath('tests/content/drafts')]
final class Draft extends Model implements PaperModel
{
    use Paper;
}
