<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;
use RuntimeException;

final class ExtendedPost extends Post
{
    /**
     * @return HasManyPaper<Page>
     */
    public function pages(): HasManyPaper
    {
        return $this->hasManyPaper(Page::class);
    }

    public function readingTime(): int
    {
        throw new RuntimeException('Relation discovery invoked a method that is not a relation.');
    }

    /**
     * @return BelongsToPaper<Author>
     */
    public static function defaultAuthor(): BelongsToPaper
    {
        throw new RuntimeException('Relation discovery invoked a static method.');
    }

    /**
     * @return BelongsToPaper<Author>
     */
    public function authorNamed(string $foreignKey): BelongsToPaper
    {
        return $this->belongsToPaper(Author::class, $foreignKey);
    }

    public function writer()
    {
        return $this->belongsToPaper(Author::class, 'author_slug');
    }

    /**
     * @return HasManyPaper<Post>
     */
    public function authorPosts(): HasManyPaper
    {
        return new Author()->posts();
    }
}
