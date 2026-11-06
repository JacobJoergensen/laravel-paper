<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Exceptions;

use RuntimeException;

final class ManifestCacheException extends RuntimeException implements PaperException
{
    public static function refused(string $store, int $bytes): self
    {
        $size = round($bytes / 1_048_576, 2);

        return new self(
            "The cache store ($store) refused the Paper manifest ($size MB), so every request rebuilds it from the files. "
            .'Point paper.cache_store at a store that holds larger values, like redis or file, or run paper:warm --compile.'
        );
    }

    public static function undeletable(string $file): self
    {
        return new self(
            "Paper could not delete the compiled manifest ($file), so it keeps serving records from before the last write. "
            .'Give the web server write access to it, or run paper:clear.'
        );
    }
}
