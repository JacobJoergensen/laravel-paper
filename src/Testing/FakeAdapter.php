<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Testing;

use JacobJoergensen\LaravelPaper\Contracts\ConditionalWriteContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\StorageAdapters\BestEffortWriter;
use JacobJoergensen\LaravelPaper\StorageAdapters\ConditionalWriteResult;

final class FakeAdapter implements ConditionalWriteContract, StorageAdapterContract
{
    /** @var array<string, array{contents: string, mtime: int}> */
    private array $files = [];

    // Nothing lands in memory between the check and the write, so the best effort is atomic here.
    private readonly BestEffortWriter $writer;

    public function __construct()
    {
        $this->writer = new BestEffortWriter($this);
    }

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

    /**
     * @return array{contents: string, version: string}|null
     */
    public function readVersioned(string $path): ?array
    {
        return $this->writer->readVersioned($path);
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function createIfMissing(string $path, string $contents, array $conflicts = []): ConditionalWriteResult
    {
        return $this->writer->createIfMissing($path, $contents, $conflicts);
    }

    public function replaceIf(string $path, string $contents, string $version): ConditionalWriteResult
    {
        return $this->writer->replaceIf($path, $contents, $version);
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function moveIf(string $from, string $to, string $contents, string $version, array $conflicts = []): ConditionalWriteResult
    {
        return $this->writer->moveIf($from, $to, $contents, $version, $conflicts);
    }

    public function deleteIf(string $path, string $version): ConditionalWriteResult
    {
        return $this->writer->deleteIf($path, $version);
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
