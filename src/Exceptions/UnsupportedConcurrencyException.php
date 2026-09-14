<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class UnsupportedConcurrencyException extends RuntimeException implements PaperException
{
    public static function forAdapter(string $adapter): self
    {
        return new self(
            "$adapter cannot guarantee conditional writes. Set paper.concurrency to best_effort or off, or point the model at storage that can."
        );
    }

    public static function forPolicy(string $policy): self
    {
        return new self("Unknown concurrency policy '$policy'. Use strict, best_effort, or off.");
    }
}
