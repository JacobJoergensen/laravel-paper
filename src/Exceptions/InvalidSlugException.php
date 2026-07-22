<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use InvalidArgumentException;

final class InvalidSlugException extends InvalidArgumentException implements PaperException
{
    public static function forSlug(string $slug): self
    {
        return new self(
            "The slug '$slug' is not a valid path. Segments must be separated by single forward slashes and cannot be '.', '..', or contain null bytes."
        );
    }

    public static function missing(): self
    {
        return new self('A slug is required to create a record.');
    }
}
