<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\PaperCollection;

/**
 * @extends PaperCollection<Post>
 */
final class PostCollection extends PaperCollection
{
    public function published(): self
    {
        return $this->filter(fn (Post $post): bool => $post->getAttribute('published') === true);
    }
}
