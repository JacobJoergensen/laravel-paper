<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use InvalidArgumentException;

final class InvalidDriverException extends InvalidArgumentException
{
    public static function notFound(string $driver): self
    {
        return new self(
            "Driver '$driver' is not registered. Available drivers can be registered via PaperServiceProvider."
        );
    }
}
