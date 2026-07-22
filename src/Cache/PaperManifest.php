<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Illuminate\Contracts\Cache\Repository;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;

/**
 * @internal
 */
final class PaperManifest
{
    private const string PREFIX = 'paper:manifest:';

    public function __construct(
        private readonly Repository $cache,
    ) {}

    /**
     * @return array<string, array{mtime: int, data: array<string, mixed>}>
     */
    public function records(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $index = $this->index($adapter, $driver, $contentPath, $nested);
        $key = $this->key($adapter, $contentPath);
        $cached = $this->read($key);

        $entries = [];
        $changed = false;

        foreach ($index as $slug => $info) {
            $existing = $cached[$slug] ?? null;

            // Compared exactly, not with >=, so a file restored to an older mtime still reparses.
            if ($existing !== null && $existing['mtime'] === $info['mtime']) {
                $entries[$slug] = $existing;

                continue;
            }

            $contents = $adapter->read($info['path']) ?? '';

            try {
                $data = $driver->parse($contents);
            } catch (FileParseException $e) {
                throw FileParseException::inFile($info['path'], $e);
            }

            $entries[$slug] = ['mtime' => $info['mtime'], 'data' => $data];
            $changed = true;
        }

        if (! $changed && array_diff_key($cached, $entries) !== []) {
            $changed = true;
        }

        if ($changed) {
            $this->store($key, $entries);
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    public function slugs(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $index = $this->index($adapter, $driver, $contentPath, $nested);

        return array_map(strval(...), array_keys($index));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(StorageAdapterContract $adapter, string $contentPath, string $slug, int $mtime, array $data): void
    {
        $key = $this->key($adapter, $contentPath);
        $entries = $this->read($key);

        $entries[$slug] = ['mtime' => $mtime, 'data' => $data];

        $this->store($key, $entries);
    }

    public function forget(StorageAdapterContract $adapter, string $contentPath, string $slug): void
    {
        $key = $this->key($adapter, $contentPath);
        $entries = $this->read($key);

        unset($entries[$slug]);

        $this->store($key, $entries);
    }

    public function flush(StorageAdapterContract $adapter, string $contentPath): void
    {
        $this->cache->forget($this->key($adapter, $contentPath));
    }

    /**
     * @return array<string, array{path: string, mtime: int}>
     */
    private function index(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested): array
    {
        $priority = array_flip($driver->extensions());
        $byslug = [];

        foreach ($adapter->listing($contentPath, $driver->extensions(), $nested) as $path => $mtime) {
            $relative = $this->relativePath($path, $contentPath);
            $extension = pathinfo($relative, PATHINFO_EXTENSION);
            $slug = substr($relative, 0, -(strlen($extension) + 1));
            $rank = $priority[$extension] ?? PHP_INT_MAX;

            $existing = $byslug[$slug] ?? null;

            if ($existing === null || $rank < $existing['rank']) {
                $byslug[$slug] = ['path' => $path, 'mtime' => $mtime, 'rank' => $rank];
            }
        }

        ksort($byslug, SORT_STRING);

        return array_map(
            static fn (array $info): array => ['path' => $info['path'], 'mtime' => $info['mtime']],
            $byslug,
        );
    }

    /**
     * The slug is the listed path relative to the content directory, without its extension.
     */
    private function relativePath(string $path, string $contentPath): string
    {
        $normalized = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $contentPath), '/').'/';

        return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
    }

    /**
     * @return array<string, array{mtime: int, data: array<string, mixed>}>
     */
    private function read(string $key): array
    {
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            return [];
        }

        /** @var array<string, array{mtime: int, data: array<string, mixed>}> $cached */
        return $cached;
    }

    /**
     * @param  array<string, array{mtime: int, data: array<string, mixed>}>  $entries
     */
    private function store(string $key, array $entries): void
    {
        $this->cache->forever($key, $entries);
    }

    private function key(StorageAdapterContract $adapter, string $contentPath): string
    {
        return self::PREFIX.md5($adapter->cacheKey($contentPath));
    }
}
