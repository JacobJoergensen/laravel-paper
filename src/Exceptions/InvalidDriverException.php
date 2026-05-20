<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use InvalidArgumentException;

final class InvalidDriverException extends InvalidArgumentException implements PaperException
{
    public static function notFound(string $driver): self
    {
        return new self(
            "Driver '$driver' is not registered. Register it with DriverRegistry::register() in a service provider."
        );
    }
}
