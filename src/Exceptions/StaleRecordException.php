<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class StaleRecordException extends RuntimeException implements PaperException
{
    public static function changed(string $slug): self
    {
        return new self("The record '$slug' changed on disk after it was loaded. Reload it and apply the change again.");
    }

    public static function gone(string $slug): self
    {
        return new self("The record '$slug' was removed from disk after it was loaded.");
    }

    public static function unverifiable(string $slug): self
    {
        return new self("The record '$slug' carries no version, so a write cannot be checked. Load it through a query before saving.");
    }
}
