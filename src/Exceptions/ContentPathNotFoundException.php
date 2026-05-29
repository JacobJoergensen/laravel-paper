<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class ContentPathNotFoundException extends RuntimeException implements PaperException
{
    public static function forPath(string $path, ?string $model = null): self
    {
        $suffix = $model !== null ? " for model $model" : '';

        return new self(
            "Content path '$path' not found$suffix. Ensure the directory exists and is readable."
        );
    }
}
