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
     * @return array<string, int>
     */
    public function listing(string $directory, array $extensions, bool $nested = false): array
    {
        if (! $this->files->isDirectory($directory)) {
            throw ContentPathNotFoundException::forPath($directory);
        }

        $matches = [];
        $visited = [];

        $this->collect($directory, array_flip($extensions), $nested, $matches, $visited);

        return $matches;
    }

    /**
     * @param  array<string, int>  $allowed
     * @param  array<string, int>  $matches
     * @param  array<string, true>  $visited
     */
    private function collect(string $directory, array $allowed, bool $nested, array &$matches, array &$visited): void
    {
        // Resolved rather than checked with is_link, which reports false for a Windows junction.
        $resolved = realpath($directory);

        if ($resolved === false || isset($visited[$resolved])) {
            return;
        }

        $visited[$resolved] = true;

        $entries = scandir($directory, SCANDIR_SORT_NONE) ?: [];

        foreach ($entries as $entry) {
            if ($entry[0] === '.') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (isset($allowed[pathinfo($entry, PATHINFO_EXTENSION)])) {
                $mtime = @filemtime($path);
                $matches[$path] = $mtime === false ? 0 : $mtime;

                continue;
            }

            if ($nested && ! is_link($path) && is_dir($path)) {
                $this->collect($path, $allowed, $nested, $matches, $visited);
            }
        }
    }
}
