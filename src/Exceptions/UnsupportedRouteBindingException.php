<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class UnsupportedRouteBindingException extends RuntimeException implements PaperException
{
    public static function scopedChild(string $childType): self
    {
        return new self(
            "Paper models do not support scoped route bindings for '$childType'. Resolve the child model in your controller instead."
        );
    }
}
