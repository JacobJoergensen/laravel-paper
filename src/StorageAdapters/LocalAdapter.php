<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

use Closure;
use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Contracts\ConditionalWriteContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;

final readonly class LocalAdapter implements ConditionalWriteContract, StorageAdapterContract
{
    private const string TEMP_PREFIX = '.paper-';

    private BestEffortWriter $writer;

    public function __construct(
        private Filesystem $files,
        private string $lockDirectory,
    ) {
        $this->writer = new BestEffortWriter($this);
    }

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
        return $this->locked(
            [$path, ...$conflicts],
            fn (): ConditionalWriteResult => $this->writer->createIfMissing($path, $contents, $conflicts),
        );
    }

    public function replaceIf(string $path, string $contents, ?string $version): ConditionalWriteResult
    {
        return $this->locked(
            [$path],
            fn (): ConditionalWriteResult => $this->writer->replaceIf($path, $contents, $version),
        );
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function moveIf(string $from, string $to, string $contents, ?string $version, array $conflicts = []): ConditionalWriteResult
    {
        return $this->locked([$from, $to, ...$conflicts], function () use ($from, $to, $contents, $version, $conflicts): ConditionalWriteResult {
            $fromInode = @fileinode($from);

            $changesCase = mb_strtolower($from) === mb_strtolower($to)
                && $fromInode !== false
                && $fromInode === @fileinode($to);

            if (! $changesCase) {
                return $this->writer->moveIf($from, $to, $contents, $version, $conflicts);
            }

            $current = $this->readVersioned($from);

            if ($current === null) {
                return ConditionalWriteResult::missing();
            }

            if ($version !== null && $current['version'] !== $version) {
                return ConditionalWriteResult::mismatch();
            }

            $taken = array_find($conflicts, $this->exists(...));

            if ($taken !== null) {
                return ConditionalWriteResult::taken($taken);
            }

            if (! @rename($from, $to)) {
                return ConditionalWriteResult::failed();
            }

            $written = $this->writer->replaceIf($to, $contents, $version);

            if ($written->status !== ConditionalWriteStatus::Written) {
                @rename($to, $from);
            }

            return $written;
        });
    }

    public function deleteIf(string $path, ?string $version): ConditionalWriteResult
    {
        return $this->locked(
            [$path],
            fn (): ConditionalWriteResult => $this->writer->deleteIf($path, $version),
        );
    }

    /**
     * @param  list<string>  $paths
     * @param  Closure(): ConditionalWriteResult  $work
     */
    private function locked(array $paths, Closure $work): ConditionalWriteResult
    {
        $this->files->ensureDirectoryExists($this->lockDirectory);

        $lockOrder = array_unique(array_map($this->lockFile(...), $paths));
        sort($lockOrder);

        $handles = [];

        try {
            foreach ($lockOrder as $lockFile) {
                $handle = @fopen($lockFile, 'c');

                if ($handle === false || ! flock($handle, LOCK_EX)) {
                    if ($handle !== false) {
                        fclose($handle);
                    }

                    return ConditionalWriteResult::failed();
                }

                $handles[] = $handle;
            }

            return $work();
        } finally {
            foreach ($handles as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function lockFile(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $extension = pathinfo($normalized, PATHINFO_EXTENSION);
        $record = $extension === '' ? $normalized : substr($normalized, 0, -(strlen($extension) + 1));

        return $this->lockDirectory.'/'.hash('sha256', mb_strtolower($record)).'.lock';
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

            if (is_dir($path)) {
                if ($nested && ! is_link($path)) {
                    $this->collect($path, $allowed, $nested, $matches, $visited);
                }

                continue;
            }

            if (isset($allowed[pathinfo($entry, PATHINFO_EXTENSION)])) {
                $mtime = @filemtime($path);
                $matches[$path] = $mtime === false ? 0 : $mtime;
            }
        }
    }

    /**
     * @internal
     *
     * @param  list<string>  $slugs
     * @param  list<string>  $extensions
     * @return array<string, array{path: string, mtime: int, ext: string}>
     */
    public function locate(string $directory, array $slugs, array $extensions, bool $nested): array
    {
        if (! $this->files->isDirectory($directory)) {
            throw ContentPathNotFoundException::forPath($directory);
        }

        $located = [];
        $entries = [];

        foreach ($slugs as $slug) {
            $segments = explode('/', $slug);
            $name = array_pop($segments);
            $parent = $directory;

            if (! $nested && $segments !== []) {
                continue;
            }

            foreach ($segments as $segment) {
                $entries[$parent] ??= array_flip(scandir($parent, SCANDIR_SORT_NONE) ?: []);
                $path = $parent.'/'.$segment;

                if (! isset($entries[$parent][$segment]) || is_link($path) || ! is_dir($path)) {
                    continue 2;
                }

                $parent = $path;
            }

            $entries[$parent] ??= array_flip(scandir($parent, SCANDIR_SORT_NONE) ?: []);

            foreach ($extensions as $extension) {
                $file = $name.'.'.$extension;
                $path = $parent.'/'.$file;
                $mtime = isset($entries[$parent][$file]) && ! is_dir($path) ? @filemtime($path) : false;

                if ($mtime !== false) {
                    $located[$slug] = ['path' => $path, 'mtime' => $mtime, 'ext' => $extension];

                    continue 2;
                }
            }
        }

        return $located;
    }
}
