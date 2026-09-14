<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

use JacobJoergensen\LaravelPaper\StorageAdapters\ConditionalWriteResult;

/**
 * A storage backend that applies a condition and its write as one atomic step. Version tokens are
 * opaque and belong to the implementation, so a backend can hand back its own (an ETag, a revision).
 * A write that lands reports Written with the new token; only a delete succeeds without one.
 */
interface ConditionalWriteContract
{
    /**
     * @return array{contents: string, version: string}|null
     */
    public function readVersioned(string $path): ?array;

    /**
     * @param  list<string>  $conflicts  Paths that must be free as well, checked under the same lock.
     */
    public function createIfMissing(string $path, string $contents, array $conflicts = []): ConditionalWriteResult;

    public function replaceIf(string $path, string $contents, string $version): ConditionalWriteResult;

    /**
     * @param  list<string>  $conflicts  Paths that must be free as well, checked under the same lock.
     */
    public function moveIf(string $from, string $to, string $contents, string $version, array $conflicts = []): ConditionalWriteResult;

    public function deleteIf(string $path, string $version): ConditionalWriteResult;
}
