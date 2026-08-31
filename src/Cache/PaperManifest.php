<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
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
     * Skips the trusted cache, so paper:warm reflects the disk even with the watcher off.
     *
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>
     */
    public function reconcile(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $revision = $this->revision($key);
        $index = $this->index($adapter, $driver, $contentPath, $nested);
        $cached = $this->read($key);

        if ($cached !== null && $this->current($cached, $index)) {
            return $cached;
        }

        $rebuilt = $this->locked($key, fn (): array => $this->build($adapter, $driver, $contentPath, $index, $key, $revision));

        // Another process is rebuilding: serve this request from disk and leave its manifest alone.
        return $rebuilt ?? $this->entries($adapter, $driver, $contentPath, $index, $cached ?? []);
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
    private function current(array $cached, array $index): bool
    {
        if (count($cached) !== count($index)) {
            return false;
        }

        return array_all($index, fn (array $info, string $slug): bool => $this->fresh($cached[$slug] ?? null, $info));
    }

    /**
     * The mtime is compared exactly, not with >=, so a file restored to an older mtime still reparses.
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
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult|null
     */
    private function locked(string $key, Closure $work): mixed
    {
        $lock = $this->lock($key);

        if ($lock === null) {
            return $work();
        }

        try {
            $lock->block($this->lockWait);
        } catch (LockTimeoutException) {
            return null;
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Reads the cache again inside the lock, because another process may have rebuilt it meanwhile.
     *
     * @param  array<string, array{path: string, mtime: int, ext: string}>  $index
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>
     */
    private function build(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, array $index, string $key, ?string $revision): array
    {
        $cached = $this->shared($key);

        if ($cached !== null && $this->current($cached, $index)) {
            return $cached;
        }

        $entries = $this->entries($adapter, $driver, $contentPath, $index, $cached ?? []);

        $this->store($key, $entries, $revision);

        return $entries;
    }

    /**
     * An entry the listing missed is kept when its file is still there.
     *
     * @param  array<string, array{path: string, mtime: int, ext: string}>  $index
     * @param  array<string, array{mtime: int, ext: string, data: array<string, mixed>}>  $cached
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>
     */
    private function entries(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, array $index, array $cached): array
    {
        $entries = [];

        foreach ($index as $slug => $info) {
            $entries[$slug] = $this->entryFor($adapter, $driver, $cached[$slug] ?? null, $info);
        }

        foreach ($cached as $slug => $entry) {
            if (isset($entries[$slug])) {
                continue;
            }

            $path = $contentPath.'/'.$slug.'.'.$entry['ext'];
            $mtime = $adapter->lastModified($path);

            if ($mtime === null) {
                continue;
            }

            $info = ['path' => $path, 'mtime' => $mtime, 'ext' => $entry['ext']];

            $entries[$slug] = $this->entryFor($adapter, $driver, $entry, $info);
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param  array{mtime: int, ext: string, data: array<string, mixed>}|null  $existing
     * @param  array{path: string, mtime: int, ext: string}  $info
     * @return array{mtime: int, ext: string, data: array<string, mixed>}
     */
    private function entryFor(StorageAdapterContract $adapter, DriverContract $driver, ?array $existing, array $info): array
    {
        if ($this->fresh($existing, $info)) {
            return $existing;
        }

        $data = $this->data($adapter, $driver, $info['path']);

        return $this->entry($driver, $info, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(StorageAdapterContract $adapter, DriverContract $driver, string $path): array
    {
        $contents = $adapter->read($path);

        if ($contents === null) {
            throw FileParseException::unreadable($path);
        }

        try {
            return $driver->parse($contents);
        } catch (FileParseException $e) {
            throw FileParseException::inFile($path, $e);
        }
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

        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $revision = $this->revision($key);
        $index = $this->index($adapter, $driver, $contentPath, $nested);
        $info = $index[$slug] ?? null;

        if ($info === null) {
            return null;
        }

        $cached = $this->read($key) ?? [];
        $existing = $cached[$slug] ?? null;

        if ($this->fresh($existing, $info)) {
            return ['slug' => $slug, ...$existing];
        }

        $data = $this->data($adapter, $driver, $info['path']);
        $entry = $this->entry($driver, $info, $data);

        // The lock protects caching the entry, not the record, so a timeout only costs the next reader a parse.
        $this->locked($key, function () use ($key, $slug, $entry, $revision): void {
            $entries = $this->shared($key) ?? [];
            $entries[$slug] = $entry;

            $this->store($key, $entries, $revision);
        });

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
        $data = $this->data($adapter, $driver, $path);

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
        $this->invalidate($this->key($adapter, $driver, $contentPath, $nested));
    }

    /**
     * A manifest that could not be locked is dropped instead of merged into, because an unlocked
     * read-modify-write would bury what another process wrote.
     *
     * @param  Closure(array<string, array{mtime: int, ext: string, data: array<string, mixed>}>): array<string, array{mtime: int, ext: string, data: array<string, mixed>}>  $change
     */
    private function mutate(string $key, Closure $change): void
    {
        $merged = $this->locked($key, function () use ($key, $change): array {
            $revision = $this->revision($key);
            $cached = $this->shared($key);

            if ($cached === null) {
                return [];
            }

            $entries = $change($cached);

            $this->store($key, $entries, $revision);

            return $entries;
        });

        if ($merged === null) {
            $this->invalidate($key);
        }
    }

    /**
     * The revision moves first, so a rebuild under way cannot pass its check and bury this invalidation.
     */
    private function invalidate(string $key): void
    {
        $this->cache->forever($key.':revision', Str::random());

        $this->drop($key);
    }

    private function drop(string $key): void
    {
        unset($this->memo[$key]);

        $this->cache->forget($key);
    }

    private function revision(string $key): ?string
    {
        $revision = $this->cache->get($key.':revision');

        return is_string($revision) ? $revision : null;
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
     * Reads past the memo, so a rebuild sees what other processes stored.
     *
     * @return array<string, array{mtime: int, ext: string, data: array<string, mixed>}>|null
     */
    private function shared(string $key): ?array
    {
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            unset($this->memo[$key]);

            return null;
        }

        /** @var array<string, array{mtime: int, ext: string, data: array<string, mixed>}> $cached */
        $this->memo[$key] = $cached;

        return $cached;
    }

    /**
     * The revision is checked on both sides of the write, because the cache has no compare-and-set.
     *
     * @param  array<string, array{mtime: int, ext: string, data: array<string, mixed>}>  $entries
     */
    private function store(string $key, array $entries, ?string $revision): void
    {
        if ($this->revision($key) !== $revision) {
            return;
        }

        $this->memo[$key] = $entries;
        $this->cache->forever($key, $entries);

        if ($this->revision($key) !== $revision) {
            $this->drop($key);
        }
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
