<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;

final readonly class LocalAdapter implements StorageAdapterContract
{
    private const string TEMP_PREFIX = '.paper-';

    public function __construct(
        private Filesystem $files,
    ) {}

    public function read(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function write(string $path, string $contents): bool
    {
        $tempPath = @tempnam(dirname($path), self::TEMP_PREFIX);

        if ($tempPath === false) {
            return false;
        }

        @chmod($tempPath, 0666 & ~umask());

        $success = @file_put_contents($tempPath, $contents) !== false
            && @rename($tempPath, $path);

        if (! $success) {
            @unlink($tempPath);
        }

        return $success;
    }

    public function delete(string $path): bool
    {
        return $this->files->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->files->exists($path);
    }

    public function lastModified(string $path): ?int
    {
        $mtime = @filemtime($path);

        return $mtime === false ? null : $mtime;
    }

    public function cacheKey(string $path): string
    {
        return $path;
    }

    public function ensureDirectoryExists(string $path): void
    {
        $this->files->ensureDirectoryExists($path);
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public function list(string $directory, array $extensions): array
    {
        if (! $this->files->isDirectory($directory)) {
            throw ContentPathNotFoundException::forPath($directory);
        }

        $matches = [];

        foreach ($extensions as $extension) {
            foreach ($this->files->glob($directory.'/*.'.$extension) ?: [] as $path) {
                if (is_string($path)) {
                    $matches[] = $path;
                }
            }
        }

        return $matches;
    }
}
