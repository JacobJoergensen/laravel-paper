<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Testing;

use JacobJoergensen\LaravelPaper\Contracts\ConditionalWriteContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\PaperVersion;
use JacobJoergensen\LaravelPaper\StorageAdapters\ConditionalWriteResult;

final class FakeAdapter implements ConditionalWriteContract, StorageAdapterContract
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

    /**
     * @return array{contents: string, version: string}|null
     */
    public function readVersioned(string $path): ?array
    {
        $contents = $this->read($path);

        if ($contents === null) {
            return null;
        }

        return ['contents' => $contents, 'version' => PaperVersion::of($contents)];
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function createIfMissing(string $path, string $contents, array $conflicts = []): ConditionalWriteResult
    {
        $taken = $this->firstTaken([$path, ...$conflicts]);

        if ($taken !== null) {
            return $taken;
        }

        $this->write($path, $contents);

        return ConditionalWriteResult::written(PaperVersion::of($contents));
    }

    public function replaceIf(string $path, string $contents, string $version): ConditionalWriteResult
    {
        $mismatch = $this->verify($path, $version);

        if ($mismatch !== null) {
            return $mismatch;
        }

        $this->write($path, $contents);

        return ConditionalWriteResult::written(PaperVersion::of($contents));
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function moveIf(string $from, string $to, string $contents, string $version, array $conflicts = []): ConditionalWriteResult
    {
        $mismatch = $this->verify($from, $version);

        if ($mismatch !== null) {
            return $mismatch;
        }

        $taken = $this->firstTaken([$to, ...$conflicts]);

        if ($taken !== null) {
            return $taken;
        }

        $this->write($to, $contents);
        $this->delete($from);

        return ConditionalWriteResult::written(PaperVersion::of($contents));
    }

    public function deleteIf(string $path, string $version): ConditionalWriteResult
    {
        $mismatch = $this->verify($path, $version);

        if ($mismatch !== null) {
            return $mismatch;
        }

        $this->delete($path);

        return ConditionalWriteResult::removed();
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstTaken(array $paths): ?ConditionalWriteResult
    {
        foreach ($paths as $path) {
            if ($this->exists($path)) {
                return ConditionalWriteResult::taken($path);
            }
        }

        return null;
    }

    private function verify(string $path, string $version): ?ConditionalWriteResult
    {
        $current = $this->readVersioned($path);

        if ($current === null) {
            return ConditionalWriteResult::missing();
        }

        return $current['version'] === $version ? null : ConditionalWriteResult::mismatch();
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
