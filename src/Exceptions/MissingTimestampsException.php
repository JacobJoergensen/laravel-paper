<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use LogicException;

final class MissingTimestampsException extends LogicException implements PaperException
{
    public static function forTimeOrdering(string $model): self
    {
        return new self(
            "Cannot use latest() or oldest() on $model without #[Timestamps]. Add the attribute to derive updated_at from the file mtime, or order by a frontmatter column, e.g. latest('date')."
        );
    }
}
