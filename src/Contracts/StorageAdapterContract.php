<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

interface StorageAdapterContract
{
    public function read(string $path): ?string;

    public function write(string $path, string $contents): bool;

    public function delete(string $path): bool;

    public function exists(string $path): bool;

    public function lastModified(string $path): ?int;

    public function cacheKey(string $path): string;

    public function ensureDirectoryExists(string $path): void;

    /**
     * @param  list<string>  $extensions
     * @return array<string, int>
     */
    public function listing(string $directory, array $extensions): array;
}
