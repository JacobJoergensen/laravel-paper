<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('json')]
#[ContentPath('tests/content/authors')]
final class Editor extends PaperModel
{
    public function posts(): PublishedPosts
    {
        return new PublishedPosts($this, Post::class, 'author_slug');
    }
}
