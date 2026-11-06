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
        return match (true) {
            $policy instanceof self => $policy,
            $policy === null => self::BestEffort,
            is_string($policy) => self::tryFrom($policy) ?? throw UnsupportedConcurrencyException::forPolicy($policy),
            default => throw UnsupportedConcurrencyException::forPolicy(var_export($policy, true)),
        };
    }
}
