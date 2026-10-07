<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

use Closure;
use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Contracts\ConditionalWriteContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;

/**
 * The lock is advisory and only holds between Paper processes; an editor writing the file ignores it.
 */
final readonly class LocalAdapter implements ConditionalWriteContract, StorageAdapterContract
{
    private const string TEMP_PREFIX = '.paper-';

    private BestEffortWriter $writer;

    public function __construct(
        private Filesystem $files,
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

    public function replaceIf(string $path, string $contents, string $version): ConditionalWriteResult
    {
        return $this->locked(
            [$path],
            fn (): ConditionalWriteResult => $this->writer->replaceIf($path, $contents, $version),
        );
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function moveIf(string $from, string $to, string $contents, string $version, array $conflicts = []): ConditionalWriteResult
    {
        return $this->locked([$from, $to, ...$conflicts], function () use ($from, $to, $contents, $version, $conflicts): ConditionalWriteResult {
            // A case-insensitive filesystem finds the record itself under a slug that only changes case.
            $changesCase = strcasecmp($from, $to) === 0
                && $this->exists($from)
                && $this->exists($to)
                && fileinode($from) === fileinode($to);

            if (! $changesCase) {
                return $this->writer->moveIf($from, $to, $contents, $version, $conflicts);
            }

            $current = $this->readVersioned($from);

            if ($current === null) {
                return ConditionalWriteResult::missing();
            }

            if ($current['version'] !== $version) {
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

    public function deleteIf(string $path, string $version): ConditionalWriteResult
    {
        return $this->locked(
            [$path],
            fn (): ConditionalWriteResult => $this->writer->deleteIf($path, $version),
        );
    }

    /**
     * Locks the records, not the filenames, so the same slug under another extension waits too.
     *
     * @param  list<string>  $paths
     * @param  Closure(): ConditionalWriteResult  $work
     */
    private function locked(array $paths, Closure $work): ConditionalWriteResult
    {
        $keys = array_unique(array_map($this->lockFile(...), $paths));

        // Sorted, so two renames crossing each other cannot each hold what the other waits for.
        sort($keys);

        $handles = [];

        try {
            foreach ($keys as $key) {
                $handle = @fopen($key, 'c');

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

        return sys_get_temp_dir().'/paper-'.hash('sha256', $record).'.lock';
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
