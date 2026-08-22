<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use BadMethodCallException;

final class UnsupportedCollectionMethodException extends BadMethodCallException implements PaperException
{
    public static function forMethod(string $method): self
    {
        return new self(
            "$method() is not supported on a Paper collection. It runs a database query, and a Paper record has no table."
        );
    }
}
