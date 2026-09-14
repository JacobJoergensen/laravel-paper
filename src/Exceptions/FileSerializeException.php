<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class FileSerializeException extends RuntimeException implements PaperException
{
    public static function invalidJson(string $error): self
    {
        return new self("Failed to serialize JSON: $error");
    }

    public static function invalidYaml(string $error): self
    {
        return new self("Failed to serialize YAML: $error");
    }
}
