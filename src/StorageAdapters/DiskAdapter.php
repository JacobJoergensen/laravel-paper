<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;

final readonly class DiskAdapter implements StorageAdapterContract
{
    public function __construct(
        private Filesystem $disk,
        private string $name,
    ) {}

    public function read(string $path): ?string
    {
        try {
            $contents = $this->disk->get($path);
        } catch (FilesystemException $exception) {
            if ($this->exists($path)) {
                throw $exception;
            }

            return null;
        }

        return is_string($contents) ? $contents : null;
    }

    public function write(string $path, string $contents): bool
    {
        try {
            return $this->disk->put($path, $contents);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            return $this->disk->delete($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    public function lastModified(string $path): ?int
    {
        try {
            return $this->disk->lastModified($path);
        } catch (FilesystemException) {
            return null;
        }
    }

    public function cacheKey(string $path): string
    {
        return 'disk:'.$this->name.':'.$path;
    }

    public function ensureDirectoryExists(string $path): void
    {
        $this->disk->makeDirectory($path);
    }

    /**
     * @param  list<string>  $extensions
     * @return array<string, int>
     */
    public function listing(string $directory, array $extensions, bool $nested = false): array
    {
        $allowed = array_flip($extensions);
        $prefix = rtrim($directory, '/').'/';
        $found = [];

        if ($this->disk instanceof FilesystemAdapter) {
            foreach ($this->disk->getDriver()->listContents($directory, $nested) as $attributes) {
                if ($attributes instanceof FileAttributes) {
                    $found[$attributes->path()] = $attributes->lastModified();
                }
            }
        } else {
            $files = $nested ? $this->disk->allFiles($directory) : $this->disk->files($directory);
            $found = array_fill_keys($files, null);
        }

        $matches = [];

        foreach ($found as $path => $mtime) {
            $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
            $hidden = str_starts_with($relative, '.') || str_contains($relative, '/.');

            if (! $hidden && isset($allowed[pathinfo($path, PATHINFO_EXTENSION)])) {
                $matches[$path] = $mtime ?? $this->lastModified($path) ?? 0;
            }
        }

        return $matches;
    }
}
