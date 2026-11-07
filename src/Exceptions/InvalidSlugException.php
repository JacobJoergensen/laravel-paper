<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use InvalidArgumentException;

final class InvalidSlugException extends InvalidArgumentException implements PaperException
{
    public static function forSlug(string $slug): self
    {
        return new self(
            "The slug '$slug' is not a valid path. Segments must be separated by single forward slashes and cannot start with a dot or contain null bytes."
        );
    }

    public static function requiresNesting(string $slug): self
    {
        return new self(
            "The slug '$slug' points into a subdirectory, which this model does not read. Add nested: true to its #[ContentPath] attribute."
        );
    }

    public static function forWindows(string $slug): self
    {
        return new self(
            "The slug '$slug' is not a valid filename on Windows. It has a reserved name like CON, a forbidden character like : or a tab, or a segment ending in a dot or space."
        );
    }

    public static function notAList(string $column, string $model, string $slug): self
    {
        return new self("The '$column' attribute on $model '$slug' must be a list of slugs.");
    }

    public static function missing(): self
    {
        return new self('A slug is required to create a record.');
    }
}
