<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('markdown')]
#[ContentPath('blog')]
final class RawNestedModel extends Model implements PaperModel
{
    use Paper;

    /** @var list<string> */
    protected $guarded = [];
}
