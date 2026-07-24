<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('markdown')]
#[ContentPath('tests/content/invalid')]
final class BrokenModel extends Model implements PaperModel
{
    use Paper;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
