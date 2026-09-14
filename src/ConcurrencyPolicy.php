<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedConcurrencyException;

enum ConcurrencyPolicy: string
{
    case Strict = 'strict';
    case BestEffort = 'best_effort';
    case Off = 'off';

    public static function fromConfig(mixed $policy): self
    {
        if (! is_string($policy)) {
            return self::BestEffort;
        }

        return self::tryFrom($policy) ?? throw UnsupportedConcurrencyException::forPolicy($policy);
    }
}
