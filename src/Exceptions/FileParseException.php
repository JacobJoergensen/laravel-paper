<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class FileParseException extends RuntimeException implements PaperException
{
    public static function invalidJson(string $error): self
    {
        return new self("Failed to parse JSON: $error");
    }

    public static function inFile(string $filepath, self $previous): self
    {
        return new self(
            "Failed to parse file '$filepath': {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
