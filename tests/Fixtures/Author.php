<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;

#[Driver('json')]
#[ContentPath('tests/content/authors')]
final class Author extends PaperModel
{
    /**
     * @return HasManyPaper<Post>
     */
    public function posts(): HasManyPaper
    {
        return $this->hasManyPaper(Post::class);
    }
}
