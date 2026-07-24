<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Testing;

use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;

final class FakeAdapter implements StorageAdapterContract
{
    /** @var array<string, array{contents: string, mtime: int}> */
    private array $files = [];

    public function seed(string $path, string $contents, int $mtime): void
    {
        $this->files[$path] = ['contents' => $contents, 'mtime' => $mtime];
    }

    public function read(string $path): ?string
    {
        return $this->files[$path]['contents'] ?? null;
    }

    public function write(string $path, string $contents): bool
    {
        $mtime = ($this->files[$path]['mtime'] ?? 0) + 1;
        $this->files[$path] = ['contents' => $contents, 'mtime' => $mtime];

        return true;
    }

    public function delete(string $path): bool
    {
        unset($this->files[$path]);

        return true;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function lastModified(string $path): ?int
    {
        return $this->files[$path]['mtime'] ?? null;
    }

    public function cacheKey(string $path): string
    {
        return 'fake:'.spl_object_id($this).':'.$path;
    }

    public function ensureDirectoryExists(string $path): void {}

    /**
     * @param  list<string>  $extensions
     * @return array<string, int>
     */
    public function listing(string $directory, array $extensions, bool $nested = false): array
    {
        $allowed = array_flip($extensions);
        $matches = [];

        foreach ($this->files as $path => $file) {
            $inDirectory = $nested
                ? str_starts_with($path, $directory.'/')
                : pathinfo($path, PATHINFO_DIRNAME) === $directory;

            if ($inDirectory && isset($allowed[pathinfo($path, PATHINFO_EXTENSION)])) {
                $matches[$path] = $file['mtime'];
            }
        }

        return $matches;
    }
}
