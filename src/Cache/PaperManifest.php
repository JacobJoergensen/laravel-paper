<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Closure;
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

    /**
     * @var array<string, array<string, array{mtime: int, ext: string, data: array<string, mixed>}>>
     */
    private array $memo = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly int $lockTtl,
        private readonly int $lockWait,
        private readonly bool $watch,
    ) {}

    /**
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>
     */
    public function records(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $trusted = $this->trusted($this->key($adapter, $driver, $contentPath, $nested));

        return $trusted ?? $this->reconcile($adapter, $driver, $contentPath, $nested);
    }

    /**
     * Lists the directory and reparses what changed, skipping the trusted cache, so paper:warm
     * reflects the disk even with the watcher off.
     *
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>
     */
    public function reconcile(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $index = $this->index($adapter, $driver, $contentPath, $nested);
        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $cached = $this->read($key) ?? [];

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
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>|null
     */
    private function trusted(string $key): ?array
    {
        return $this->watch ? null : $this->read($key);
    }

    /**
     * @param  array<string, array{mtime: int, ext: string, data: array<string, mixed>}>  $cached
     * @param  array<string, array{path: string, mtime: int, ext: string}>  $index
     */
    private function stale(array $cached, array $index): bool
    {
        return array_any($index, fn (array $info, string $slug): bool => ! $this->fresh($cached[$slug] ?? null, $info));
    }

    /**
     * The mtime is compared exactly, not with >=, so a file restored to an older mtime still
     * reparses. The extension counts too, so a slug that swaps format is not served from the
     * entry built for the file it replaced.
     *
     * @param  array{mtime: int, ext: string, data: array<string, mixed>}|null  $existing
     * @param  array{mtime: int, ext: string}  $info
     *
     * @phpstan-assert-if-true array{mtime: int, ext: string, data: array<string, mixed>} $existing
     */
    private function fresh(?array $existing, array $info): bool
    {
        if ($existing === null) {
            return false;
        }

        return $existing['mtime'] === $info['mtime'] && $existing['ext'] === $info['ext'];
    }

    /**
     * @param  array<string, array{path: string, mtime: int, ext: string}>  $index
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>
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
     * @param  array<string, array{path: string, mtime: int, ext: string}>  $index
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>
     */
    private function build(StorageAdapterContract $adapter, DriverContract $driver, string $key, array $index): array
    {
        $cached = $this->read($key) ?? [];

        $entries = [];
        $changed = false;

        foreach ($index as $slug => $info) {
            $existing = $cached[$slug] ?? null;

            if ($this->fresh($existing, $info)) {
                $entries[$slug] = $existing;

                continue;
            }

            $contents = $adapter->read($info['path']);

            if ($contents === null) {
                throw FileParseException::unreadable($info['path']);
            }

            try {
                $data = $driver->parse($contents);
            } catch (FileParseException $e) {
                throw FileParseException::inFile($info['path'], $e);
            }

            $entries[$slug] = $this->entry($driver, $info, $data);
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
     * @return array{slug: string, mtime: int, ext: string, data: array<string, mixed>}|null
     */
    public function record(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, string $slug, bool $nested = false): ?array
    {
        if (! $this->watch) {
            $entries = $this->records($adapter, $driver, $contentPath, $nested);
            $entry = $entries[$slug] ?? null;

            return $entry === null ? null : ['slug' => $slug, ...$entry];
        }

        $index = $this->index($adapter, $driver, $contentPath, $nested);
        $info = $index[$slug] ?? null;

        if ($info === null) {
            return null;
        }

        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $cached = $this->read($key) ?? [];
        $existing = $cached[$slug] ?? null;

        if ($this->fresh($existing, $info)) {
            $entry = $existing;
        } else {
            $contents = $adapter->read($info['path']);

            if ($contents === null) {
                throw FileParseException::unreadable($info['path']);
            }

            try {
                $data = $driver->parse($contents);
            } catch (FileParseException $e) {
                throw FileParseException::inFile($info['path'], $e);
            }

            $entry = $this->entry($driver, $info, $data);

            $cached[$slug] = $entry;
            $this->store($key, $cached);
        }

        return ['slug' => $slug, ...$entry];
    }

    /**
     * Read straight from the file, because the manifest carries frontmatter only.
     */
    public function body(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, string $slug, bool $nested = false): mixed
    {
        $column = $driver->bodyColumn();

        if ($column === null) {
            return null;
        }

        $entry = $this->record($adapter, $driver, $contentPath, $slug, $nested);

        if ($entry === null) {
            return null;
        }

        $path = $contentPath.'/'.$slug.'.'.$entry['ext'];
        $contents = $adapter->read($path);

        if ($contents === null) {
            throw FileParseException::unreadable($path);
        }

        try {
            $data = $driver->parse($contents);
        } catch (FileParseException $e) {
            throw FileParseException::inFile($path, $e);
        }

        return $data[$column] ?? null;
    }

    /**
     * @return list<string>
     */
    public function slugs(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $key = $this->key($adapter, $driver, $contentPath, $nested);

        $trusted = $this->trusted($key);

        if ($trusted !== null) {
            return array_map(strval(...), array_keys($trusted));
        }

        $index = $this->index($adapter, $driver, $contentPath, $nested);

        return array_map(strval(...), array_keys($index));
    }

    /**
     * @return array<string, array{path: string, mtime: int, ext: string}>
     */
    public function files(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        return $this->index($adapter, $driver, $contentPath, $nested);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, string $slug, string $path, array $data, bool $nested = false): void
    {
        $info = [
            'path' => $path,
            'mtime' => $adapter->lastModified($path) ?? 0,
            'ext' => pathinfo($path, PATHINFO_EXTENSION),
        ];

        $this->mutate($this->key($adapter, $driver, $contentPath, $nested), function (array $entries) use ($driver, $slug, $info, $data): array {
            $entries[$slug] = $this->entry($driver, $info, $data);

            return $entries;
        });
    }

    /**
     * @param  array{mtime: int, ext: string}  $info
     * @param  array<string, mixed>  $data
     * @return array{mtime: int, ext: string, data: array<string, mixed>}
     */
    private function entry(DriverContract $driver, array $info, array $data): array
    {
        $column = $driver->bodyColumn();

        if ($column !== null) {
            unset($data[$column]);
        }

        return ['mtime' => $info['mtime'], 'ext' => $info['ext'], 'data' => $data];
    }

    public function forget(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, string $slug, bool $nested = false): void
    {
        $this->mutate($this->key($adapter, $driver, $contentPath, $nested), function (array $entries) use ($slug): array {
            unset($entries[$slug]);

            return $entries;
        });
    }

    public function flush(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): void
    {
        $key = $this->key($adapter, $driver, $contentPath, $nested);

        unset($this->memo[$key]);

        $this->cache->forget($key);
    }

    /**
     * @param  Closure(array<string, array{mtime: int, ext: string, data: array<string, mixed>}>): array<string, array{mtime: int, ext: string, data: array<string, mixed>}>  $change
     */
    private function mutate(string $key, Closure $change): void
    {
        $lock = $this->lock($key);

        if ($lock === null) {
            $this->apply($key, $change);

            return;
        }

        try {
            $lock->block($this->lockWait);
        } catch (LockTimeoutException) {
            $this->apply($key, $change);

            return;
        }

        try {
            $this->apply($key, $change);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Closure(array<string, array{mtime: int, ext: string, data: array<string, mixed>}>): array<string, array{mtime: int, ext: string, data: array<string, mixed>}>  $change
     */
    private function apply(string $key, Closure $change): void
    {
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            return;
        }

        /** @var array<string, array{mtime: int, ext: string, data: array<string, mixed>}> $cached */
        $this->store($key, $change($cached));
    }

    /**
     * @return array<string, array{path: string, mtime: int, ext: string}>
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
                $byslug[$slug] = ['path' => $path, 'mtime' => $mtime, 'ext' => $extension, 'rank' => $rank];
            }
        }

        ksort($byslug, SORT_STRING);

        return array_map(
            static fn (array $info): array => ['path' => $info['path'], 'mtime' => $info['mtime'], 'ext' => $info['ext']],
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
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>|null
     */
    private function read(string $key): ?array
    {
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            return null;
        }

        /** @var array<string, array{mtime: int, ext: string, data: array<string, mixed>}> $cached */
        $this->memo[$key] = $cached;

        return $cached;
    }

    /**
     * @param  array<string, array{mtime: int, ext: string, data: array<string, mixed>}>  $entries
     */
    private function store(string $key, array $entries): void
    {
        $this->memo[$key] = $entries;
        $this->cache->forever($key, $entries);
    }

    private function key(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested): string
    {
        $scope = $adapter->cacheKey($contentPath).':'.$driver::class.':'.($nested ? 'nested' : 'flat');

        return self::PREFIX.md5($scope);
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
