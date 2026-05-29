<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;

#[Driver('json')]
#[ContentPath('tests/content/authors')]
final class Author extends Model
{
    use Paper;

    public function posts(): HasManyPaper
    {
        return $this->hasManyPaper(Post::class);
    }
}
