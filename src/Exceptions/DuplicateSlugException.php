<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class DuplicateSlugException extends RuntimeException implements PaperException
{
    public static function forSlug(string $slug, string $path): self
    {
        return new self("The slug '$slug' is already taken by $path.");
    }
}
