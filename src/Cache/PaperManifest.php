<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
        private readonly int $lockTtl,
        private readonly int $lockWait,
        private readonly bool $watch,
    ) {}

    /**
     * @return array<string, array{mtime: int, data: array<string, mixed>}>
     */
    public function records(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $key = $this->key($adapter, $contentPath);

        $trusted = $this->trusted($key);

        if ($trusted !== null) {
            return $trusted;
        }

        $index = $this->index($adapter, $driver, $contentPath, $nested);
        $cached = $this->read($key);

        if ($this->stale($cached, $index)) {
            return $this->rebuild($adapter, $driver, $key, $index);
        }

        $entries = [];

        foreach (array_keys($index) as $slug) {
            $entries[$slug] = $cached[$slug];
        }

        // With the watcher off, persist even an unchanged build so the next query can trust it.
        if (! $this->watch || count($entries) !== count($cached)) {
            $this->store($key, $entries);
        }

        return $entries;
    }

    /**
     * @return array<string, array{mtime: int, data: array<string, mixed>}>|null
     */
    private function trusted(string $key): ?array
    {
        if ($this->watch) {
            return null;
        }

        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            return null;
        }

        /** @var array<string, array{mtime: int, data: array<string, mixed>}> $cached */
        return $cached;
    }

    /**
     * @param  array<string, array{mtime: int, data: array<string, mixed>}>  $cached
     * @param  array<string, array{path: string, mtime: int}>  $index
     */
    private function stale(array $cached, array $index): bool
    {
        return array_any($index, fn (array $info, string $slug): bool => ! $this->fresh($cached[$slug] ?? null, $info));
    }

    /**
     * Compared exactly, not with >=, so a file restored to an older mtime still reparses.
     *
     * @param  array{mtime: int, data: array<string, mixed>}|null  $existing
     * @param  array{mtime: int}  $info
     *
     * @phpstan-assert-if-true array{mtime: int, data: array<string, mixed>} $existing
     */
    private function fresh(?array $existing, array $info): bool
    {
        return $existing !== null && $existing['mtime'] === $info['mtime'];
    }

    /**
     * @param  array<string, array{path: string, mtime: int}>  $index
     * @return array<string, array{mtime: int, data: array<string, mixed>}>
     */
    private function rebuild(StorageAdapterContract $adapter, DriverContract $driver, string $key, array $index): array
    {
        $lock = $this->lock($key);

        if ($lock === null) {
            return $this->build($adapter, $driver, $key, $index);
        }

        try {
            $lock->block($this->lockWait);
        } catch (LockTimeoutException) {
            return $this->build($adapter, $driver, $key, $index);
        }

        try {
            return $this->build($adapter, $driver, $key, $index);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, array{path: string, mtime: int}>  $index
     * @return array<string, array{mtime: int, data: array<string, mixed>}>
     */
    private function build(StorageAdapterContract $adapter, DriverContract $driver, string $key, array $index): array
    {
        $cached = $this->read($key);

        $entries = [];
        $changed = false;

        foreach ($index as $slug => $info) {
            $existing = $cached[$slug] ?? null;

            if ($this->fresh($existing, $info)) {
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

        if (! $changed && count($entries) !== count($cached)) {
            $changed = true;
        }

        if ($changed) {
            $this->store($key, $entries);
        }

        return $entries;
    }

    /**
     * @return array{slug: string, mtime: int, data: array<string, mixed>}|null
     */
    public function record(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, string $slug, bool $nested = false): ?array
    {
        if (! $this->watch) {
            $entries = $this->records($adapter, $driver, $contentPath, $nested);
            $entry = $entries[$slug] ?? null;

            return $entry === null ? null : ['slug' => $slug, 'mtime' => $entry['mtime'], 'data' => $entry['data']];
        }

        $index = $this->index($adapter, $driver, $contentPath, $nested);
        $info = $index[$slug] ?? null;

        if ($info === null) {
            return null;
        }

        $key = $this->key($adapter, $contentPath);
        $cached = $this->read($key);
        $existing = $cached[$slug] ?? null;

        if ($this->fresh($existing, $info)) {
            $entry = $existing;
        } else {
            $contents = $adapter->read($info['path']) ?? '';

            try {
                $data = $driver->parse($contents);
            } catch (FileParseException $e) {
                throw FileParseException::inFile($info['path'], $e);
            }

            $entry = ['mtime' => $info['mtime'], 'data' => $data];

            $cached[$slug] = $entry;
            $this->store($key, $cached);
        }

        return ['slug' => $slug, 'mtime' => $entry['mtime'], 'data' => $entry['data']];
    }

    /**
     * @return list<string>
     */
    public function slugs(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $key = $this->key($adapter, $contentPath);

        $trusted = $this->trusted($key);

        if ($trusted !== null) {
            return array_map(strval(...), array_keys($trusted));
        }

        $index = $this->index($adapter, $driver, $contentPath, $nested);

        return array_map(strval(...), array_keys($index));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(StorageAdapterContract $adapter, string $contentPath, string $slug, int $mtime, array $data): void
    {
        $key = $this->key($adapter, $contentPath);

        if (! $this->cache->has($key)) {
            return;
        }

        $entries = $this->read($key);
        $entries[$slug] = ['mtime' => $mtime, 'data' => $data];

        $this->store($key, $entries);
    }

    public function forget(StorageAdapterContract $adapter, string $contentPath, string $slug): void
    {
        $key = $this->key($adapter, $contentPath);

        if (! $this->cache->has($key)) {
            return;
        }

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

    private function lock(string $key): ?Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        return $store->lock($key.':lock', $this->lockTtl);
    }
}
