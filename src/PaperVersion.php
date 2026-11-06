<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

/**
 * @internal
 */
final class PaperVersion
{
    public static function of(string $contents): string
    {
        return hash('sha256', $contents);
    }
}
