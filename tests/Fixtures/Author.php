<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('json')]
#[ContentPath('tests/content/authors')]
final class Author extends Model
{
    use Paper;

    public function posts(): Collection
    {
        return $this->hasManyPaper(Post::class);
    }
}
