<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\ManifestCacheException;
use JacobJoergensen\LaravelPaper\StorageAdapters\BestEffortWriter;

/**
 * @internal
 *
 * @phpstan-type ManifestEntry array{mtime: int, parsed: int, ext: string, data: array<string, mixed>, version: string}
 * @phpstan-type ManifestRecord array{slug: string, mtime: int, ext: string, data: array<string, mixed>, version: string}
 * @phpstan-type ManifestFile array{path: string, mtime: int, ext: string}
 */
final class PaperManifest
{
    private const string PREFIX = 'paper:manifest:';

    /**
     * @var array<string, array<string, ManifestEntry>>
     */
    private array $memo = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly int $lockTtl,
        private readonly int $lockWait,
        private readonly bool $watch,
        private readonly string $version = '',
        private readonly ?string $compiledPath = null,
        private readonly bool $opcache = false,
    ) {}

    /**
     * @return array<string, ManifestEntry>
     */
    public function records(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        bool $nested = false,
    ): array {
        $trusted = $this->trusted($this->key($adapter, $driver, $contentPath, $nested));

        return $trusted ?? $this->reconcile($adapter, $driver, $contentPath, $nested);
    }

    /**
     * @return array<string, ManifestEntry>
     */
    public function reconcile(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        bool $nested = false,
    ): array {
        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $revision = $this->revision($key);
        $index = $this->files($adapter, $driver, $contentPath, $nested);
        $cached = $this->read($key);

        if ($cached !== null && $this->current($cached, $index)) {
            return $cached;
        }

        $rebuild = function () use ($adapter, $driver, $contentPath, $index, $key, $revision): array {
            $shared = $this->shared($key);

            if ($shared !== null && $this->current($shared, $index)) {
                return $shared;
            }

            $entries = $this->entries($adapter, $driver, $contentPath, $index, $shared ?? []);

            $this->store($key, $entries, $revision);

            return $entries;
        };

        $rebuilt = $this->locked($key, $rebuild);

        return $rebuilt ?? $this->entries($adapter, $driver, $contentPath, $index, $cached ?? []);
    }

    /**
     * @return array<string, ManifestEntry>|null
     */
    private function trusted(string $key): ?array
    {
        if ($this->watch) {
            return null;
        }

        $file = $this->compiledFile($key);
        $compiled = $file !== null && $this->opcache && is_file($file) ? require $file : null;

        if (! is_array($compiled)) {
            return $this->read($key);
        }

        /** @var array<string, ManifestEntry> $compiled */
        $this->memo[$key] = $compiled;

        return $compiled;
    }

    /**
     * @return array<string, ManifestEntry>
     */
    public function compile(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        bool $nested = false,
    ): array {
        $entries = $this->reconcile($adapter, $driver, $contentPath, $nested);
        $file = $this->compiledFile($this->key($adapter, $driver, $contentPath, $nested));

        if ($file === null) {
            return $entries;
        }

        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($file));
        $files->replace($file, '<?php return '.var_export($entries, true).';'.PHP_EOL);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        return $entries;
    }

    private function forgetCompiled(string $key): void
    {
        $file = $this->compiledFile($key);

        if ($file === null || ! is_file($file)) {
            return;
        }

        if (! @unlink($file) && is_file($file)) {
            report(ManifestCacheException::undeletable($file));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    private function compiledFile(string $key): ?string
    {
        if ($this->compiledPath === null) {
            return null;
        }

        return $this->compiledPath.'/'.str_replace(':', '-', $key).'.php';
    }

    /**
     * @param  array<string, ManifestEntry>  $cached
     * @param  array<string, ManifestFile>  $index
     */
    private function current(array $cached, array $index): bool
    {
        if (count($cached) !== count($index)) {
            return false;
        }

        return array_all($index, fn (array $info, string $slug): bool => $this->fresh($cached[$slug] ?? null, $info));
    }

    /**
     * @param  ManifestEntry|null  $existing
     * @param  array{mtime: int, ext: string}  $info
     *
     * @phpstan-assert-if-true ManifestEntry $existing
     */
    private function fresh(?array $existing, array $info): bool
    {
        return $existing !== null
            && $existing['parsed'] > $existing['mtime']
            && $existing['mtime'] === $info['mtime']
            && $existing['ext'] === $info['ext'];
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult|null
     */
    private function locked(string $key, Closure $work): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $work();
        }

        $lock = $store->lock($key.':lock', $this->lockTtl);

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
     * @param  array<string, ManifestFile>  $index
     * @param  array<string, ManifestEntry>  $cached
     * @return array<string, ManifestEntry>
     */
    private function entries(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        array $index,
        array $cached,
    ): array {
        $entries = [];

        foreach ($index as $slug => $info) {
            $entry = $this->entryFor($adapter, $driver, $cached[$slug] ?? null, $info);

            if ($entry !== null) {
                $entries[$slug] = $entry;
            }
        }

        foreach ($cached as $slug => $cachedEntry) {
            if (isset($entries[$slug])) {
                continue;
            }

            $path = $contentPath.'/'.$slug.'.'.$cachedEntry['ext'];
            $mtime = $adapter->lastModified($path);

            if ($mtime === null) {
                continue;
            }

            $info = ['path' => $path, 'mtime' => $mtime, 'ext' => $cachedEntry['ext']];
            $entry = $this->entryFor($adapter, $driver, $cachedEntry, $info);

            if ($entry !== null) {
                $entries[$slug] = $entry;
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param  ManifestEntry|null  $existing
     * @param  ManifestFile  $info
     * @return ManifestEntry|null
     */
    private function entryFor(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        ?array $existing,
        array $info,
    ): ?array {
        if ($this->fresh($existing, $info)) {
            return $existing;
        }

        $read = BestEffortWriter::of($adapter)->readVersioned($info['path']);

        if ($read === null && $adapter->exists($info['path'])) {
            throw FileParseException::unreadable($info['path']);
        }

        if ($read === null) {
            return null;
        }

        $data = $this->parse($driver, $info['path'], $read['contents']);

        return $this->entry($driver, $info, $data, $read['version']);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(DriverContract $driver, string $path, string $contents): array
    {
        try {
            return $driver->parse($contents);
        } catch (FileParseException $e) {
            throw FileParseException::inFile($path, $e);
        }
    }

    /**
     * @return ManifestRecord|null
     */
    public function record(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        string $slug,
        bool $nested = false,
    ): ?array {
        return $this->recordsFor($adapter, $driver, $contentPath, [$slug], $nested)[$slug] ?? null;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, ManifestRecord>
     */
    public function recordsFor(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        array $slugs,
        bool $nested = false,
    ): array {
        $found = [];

        if (! $this->watch) {
            $entries = $this->records($adapter, $driver, $contentPath, $nested);

            foreach ($slugs as $slug) {
                if (isset($entries[$slug])) {
                    $found[$slug] = ['slug' => $slug, ...$entries[$slug]];
                }
            }

            return $found;
        }

        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $revision = $this->revision($key);
        $index = $this->files($adapter, $driver, $contentPath, $nested);
        $cached = $this->read($key) ?? [];
        $parsed = [];

        foreach ($slugs as $slug) {
            $info = $index[$slug] ?? null;

            if ($info === null) {
                continue;
            }

            $existing = $cached[$slug] ?? null;

            if (! $this->fresh($existing, $info)) {
                $existing = $this->entryFor($adapter, $driver, $existing, $info);

                if ($existing === null) {
                    continue;
                }

                $parsed[$slug] = $existing;
            }

            $found[$slug] = ['slug' => $slug, ...$existing];
        }

        if ($parsed === []) {
            return $found;
        }

        $this->locked($key, function () use ($key, $parsed, $revision): void {
            $entries = array_replace($this->shared($key) ?? [], $parsed);

            ksort($entries, SORT_STRING);

            $this->store($key, $entries, $revision);
        });

        return $found;
    }

    public function body(StorageAdapterContract $adapter, DriverContract $driver, string $path): mixed
    {
        $column = $driver->bodyColumn();

        if ($column === null) {
            return null;
        }

        $contents = $adapter->read($path);

        if ($contents === null) {
            return null;
        }

        $data = $this->parse($driver, $path, $contents);

        return $data[$column] ?? null;
    }

    /**
     * @return list<string>
     */
    public function slugs(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        bool $nested = false,
    ): array {
        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $entries = $this->trusted($key) ?? $this->files($adapter, $driver, $contentPath, $nested);

        return array_map(strval(...), array_keys($entries));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        string $slug,
        string $path,
        array $data,
        string $version,
        bool $nested = false,
    ): void {
        $info = [
            'path' => $path,
            'mtime' => $adapter->lastModified($path) ?? 0,
            'ext' => pathinfo($path, PATHINFO_EXTENSION),
        ];

        $key = $this->key($adapter, $driver, $contentPath, $nested);

        $this->mutate($key, function (array $entries) use ($driver, $slug, $info, $data, $version): array {
            $entries[$slug] = $this->entry($driver, $info, $data, $version);

            ksort($entries, SORT_STRING);

            return $entries;
        });
    }

    /**
     * @param  array{mtime: int, ext: string}  $info
     * @param  array<string, mixed>  $data
     * @return ManifestEntry
     */
    private function entry(DriverContract $driver, array $info, array $data, string $version): array
    {
        $column = $driver->bodyColumn();

        if ($column !== null) {
            unset($data[$column]);
        }

        return [
            'mtime' => $info['mtime'],
            'parsed' => now()->getTimestamp(),
            'ext' => $info['ext'],
            'data' => $data,
            'version' => $version,
        ];
    }

    public function forget(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        string $slug,
        bool $nested = false,
    ): void {
        $key = $this->key($adapter, $driver, $contentPath, $nested);

        $this->mutate($key, function (array $entries) use ($slug): array {
            unset($entries[$slug]);

            return $entries;
        });
    }

    public function flush(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        bool $nested = false,
    ): void {
        $this->invalidate($this->key($adapter, $driver, $contentPath, $nested));
    }

    /**
     * @param  Closure(array<string, ManifestEntry>): array<string, ManifestEntry>  $change
     */
    private function mutate(string $key, Closure $change): void
    {
        $this->forgetCompiled($key);

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

    private function invalidate(string $key): void
    {
        $this->forgetCompiled($key);

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
     * @return array<string, ManifestFile>
     */
    public function files(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        bool $nested = false,
    ): array {
        $priority = array_flip($driver->extensions());
        $root = rtrim(str_replace('\\', '/', $contentPath), '/').'/';
        $byslug = [];

        foreach ($adapter->listing($contentPath, $driver->extensions(), $nested) as $path => $mtime) {
            $normalized = str_replace('\\', '/', $path);
            $relative = str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
            $extension = pathinfo($relative, PATHINFO_EXTENSION);
            $slug = substr($relative, 0, -(strlen($extension) + 1));
            $rank = $priority[$extension] ?? PHP_INT_MAX;

            $existing = $byslug[$slug] ?? null;

            if ($existing === null || $rank < $existing['rank']) {
                $byslug[$slug] = ['path' => $path, 'mtime' => $mtime, 'ext' => $extension, 'rank' => $rank];
            }
        }

        ksort($byslug, SORT_STRING);

        return array_map(static fn (array $info): array => [
            'path' => $info['path'],
            'mtime' => $info['mtime'],
            'ext' => $info['ext'],
        ], $byslug);
    }

    /**
     * @return array<string, ManifestEntry>|null
     */
    private function read(string $key): ?array
    {
        return $this->memo[$key] ?? $this->shared($key);
    }

    /**
     * @return array<string, ManifestEntry>|null
     */
    private function shared(string $key): ?array
    {
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            unset($this->memo[$key]);

            return null;
        }

        /** @var array<string, ManifestEntry> $cached */
        $this->memo[$key] = $cached;

        return $cached;
    }

    /**
     * @param  array<string, ManifestEntry>  $entries
     */
    private function store(string $key, array $entries, ?string $revision): void
    {
        if ($this->revision($key) !== $revision) {
            return;
        }

        $this->memo[$key] = $entries;

        if (! $this->cache->forever($key, $entries)) {
            $store = class_basename($this->cache->getStore());

            report(ManifestCacheException::refused($store, strlen(serialize($entries))));
        }

        if ($this->revision($key) !== $revision) {
            $this->drop($key);
        }
    }

    private function key(
        StorageAdapterContract $adapter,
        DriverContract $driver,
        string $contentPath,
        bool $nested,
    ): string {
        $scope = implode(':', [
            $this->version,
            $adapter->cacheKey($contentPath),
            $driver::class,
            $nested ? 'nested' : 'flat',
            $this->watch ? 'watched' : 'trusted',
        ]);

        return self::PREFIX.md5($scope);
    }
}
