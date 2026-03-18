<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class ContentPathNotFoundException extends RuntimeException
{
    public static function forPath(string $path, string $model): self
    {
        return new self(
            "Content path '$path' not found for model $model. Ensure the directory exists and is readable."
        );
    }
}
