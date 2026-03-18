<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class FileParseException extends RuntimeException
{
    public static function invalidJson(string $filepath, string $error): self
    {
        return new self(
            "Failed to parse JSON file '$filepath': $error"
        );
    }

    public static function unreadable(string $filepath): self
    {
        return new self(
            "Cannot read file '$filepath'. Ensure the file exists and is readable."
        );
    }
}
