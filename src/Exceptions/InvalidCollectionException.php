<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use InvalidArgumentException;
use JacobJoergensen\LaravelPaper\PaperCollection;

final class InvalidCollectionException extends InvalidArgumentException implements PaperException
{
    public static function forCollection(string $collection, string $model): self
    {
        return new self(
            "Collection '$collection' on $model is not supported. A collection named by #[CollectedBy] must extend ".PaperCollection::class.'.'
        );
    }
}
