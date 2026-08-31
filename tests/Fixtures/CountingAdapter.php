<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Closure;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;

final class CountingAdapter implements StorageAdapterContract
{
    /** @var array<string, int> */
    public array $counts = ['read' => 0, 'listing' => 0, 'lastModified' => 0, 'exists' => 0, 'write' => 0, 'delete' => 0];

    public bool $failDelete = false;

    public ?string $undeletable = null;

    public bool $failRead = false;

    /** @var array<string, array{contents: string, mtime: int}> */
    private array $files = [];

    /** @var array<string, true> */
    private array $unlisted = [];

    private ?Closure $duringRead = null;

    public function seed(string $path, string $contents, int $mtime): void
    {
        $this->files[$path] = ['contents' => $contents, 'mtime' => $mtime];
    }

    public function hideFromListing(string $path): void
    {
        $this->unlisted[$path] = true;
    }

    /**
     * @param  Closure(): void  $work
     */
    public function duringNextRead(Closure $work): void
    {
        $this->duringRead = $work;
    }

    public function reset(): void
    {
        $this->counts = array_fill_keys(array_keys($this->counts), 0);
    }

    public function remove(string $path): void
    {
        unset($this->files[$path]);
    }

    public function read(string $path): ?string
    {
        $this->counts['read']++;

        if ($this->duringRead !== null) {
            $work = $this->duringRead;
            $this->duringRead = null;

            $work();
        }

        if ($this->failRead) {
            return null;
        }

        return $this->files[$path]['contents'] ?? null;
    }

    public function write(string $path, string $contents): bool
    {
        $this->counts['write']++;
        $mtime = ($this->files[$path]['mtime'] ?? 0) + 1;
        $this->files[$path] = ['contents' => $contents, 'mtime' => $mtime];

        return true;
    }

    public function delete(string $path): bool
    {
        $this->counts['delete']++;

        if ($this->failDelete || $path === $this->undeletable) {
            return false;
        }

        unset($this->files[$path]);

        return true;
    }

    public function exists(string $path): bool
    {
        $this->counts['exists']++;

        return isset($this->files[$path]);
    }

    public function lastModified(string $path): ?int
    {
        $this->counts['lastModified']++;

        return $this->files[$path]['mtime'] ?? null;
    }

    public function cacheKey(string $path): string
    {
        return 'counting:'.$path;
    }

    public function ensureDirectoryExists(string $path): void {}

    /**
     * @param  list<string>  $extensions
     * @return array<string, int>
     */
    public function listing(string $directory, array $extensions, bool $nested = false): array
    {
        $this->counts['listing']++;
        $allowed = array_flip($extensions);
        $matches = [];

        foreach ($this->files as $path => $file) {
            if (isset($this->unlisted[$path])) {
                unset($this->unlisted[$path]);

                continue;
            }

            $matchesDir = $nested
                ? str_starts_with($path, $directory.'/')
                : pathinfo($path, PATHINFO_DIRNAME) === $directory;

            if ($matchesDir && isset($allowed[pathinfo($path, PATHINFO_EXTENSION)])) {
                $matches[$path] = $file['mtime'];
            }
        }

        return $matches;
    }
}
