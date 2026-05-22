<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Collection;

/**
 * @extends Collection<int, Post>
 */
final class PostCollection extends Collection
{
    public function published(): self
    {
        return $this->filter(fn (Post $post): bool => $post->published === true);
    }
}
