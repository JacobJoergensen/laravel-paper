<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use InvalidArgumentException;

final class InvalidSlugException extends InvalidArgumentException
{
    public static function forSlug(string $slug): self
    {
        return new self(
            "The slug '$slug' is not a valid filename. It must be a single segment without slashes, '..', or null bytes."
        );
    }
}
