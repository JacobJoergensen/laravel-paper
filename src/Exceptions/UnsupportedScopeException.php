<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use InvalidArgumentException;
use JacobJoergensen\LaravelPaper\Contracts\ScopeContract;

final class UnsupportedScopeException extends InvalidArgumentException implements PaperException
{
    public static function forScope(mixed $scope): self
    {
        $given = is_string($scope) ? $scope : get_debug_type($scope);

        return new self(
            "Global scope '$given' is not supported. A Paper global scope must be a Closure or implement ".ScopeContract::class.'.'
        );
    }
}
