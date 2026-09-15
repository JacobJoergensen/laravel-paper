<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use BadMethodCallException;

final class UnsupportedDatabaseQueryException extends BadMethodCallException implements PaperException
{
    /**
     * @param  class-string  $model
     */
    public static function forModel(string $model): self
    {
        return new self(sprintf(
            '%s has no database query builder. A Paper record is a file, not a table row, so use %s::query() instead.',
            $model,
            class_basename($model),
        ));
    }
}
