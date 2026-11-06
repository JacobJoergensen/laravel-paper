<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

use JacobJoergensen\LaravelPaper\StorageAdapters\ConditionalWriteResult;

interface ConditionalWriteContract
{
    /**
     * @return array{contents: string, version: string}|null
     */
    public function readVersioned(string $path): ?array;

    /**
     * @param  list<string>  $conflicts
     */
    public function createIfMissing(string $path, string $contents, array $conflicts = []): ConditionalWriteResult;

    /**
     * @param  ?string  $version  null writes without checking
     */
    public function replaceIf(string $path, string $contents, ?string $version): ConditionalWriteResult;

    /**
     * @param  ?string  $version  null moves without checking
     * @param  list<string>  $conflicts
     */
    public function moveIf(
        string $from,
        string $to,
        string $contents,
        ?string $version,
        array $conflicts = [],
    ): ConditionalWriteResult;

    /**
     * @param  ?string  $version  null deletes without checking
     */
    public function deleteIf(string $path, ?string $version): ConditionalWriteResult;
}
