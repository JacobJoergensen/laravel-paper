<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Closure;
use Illuminate\Contracts\Cache\Lock;
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
 * @phpstan-type ManifestEntry array{mtime: int, ext: string, data: array<string, mixed>, version: string}
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
    public function records(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $trusted = $this->trusted($this->key($adapter, $driver, $contentPath, $nested));

        return $trusted ?? $this->reconcile($adapter, $driver, $contentPath, $nested);
    }

    /**
     * Skips the trusted cache, so paper:warm reflects the disk even with the watcher off.
     *
     * @return array<string, ManifestEntry>
     */
    public function reconcile(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $key = $this->key($adapter, $driver, $contentPath, $nested);
        $revision = $this->revision($key);
        $index = $this->files($adapter, $driver, $contentPath, $nested);
        $cached = $this->read($key);

        if ($cached !== null && $this->current($cached, $index)) {
            return $cached;
        }

        $rebuilt = $this->locked($key, fn (): array => $this->build($adapter, $driver, $contentPath, $index, $key, $revision));

        // Another process is rebuilding: serve this request from disk and leave its manifest alone.
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

        return $this->compiled($key) ?? $this->read($key);
    }

    /**
     * Only read while opcache serves it, because compiling the file each time costs more than unserializing.
     *
     * @return array<string, ManifestEntry>|null
     */
    private function compiled(string $key): ?array
    {
        $file = $this->compiledFile($key);

        if ($file === null || ! $this->opcache || ! is_file($file)) {
            return null;
        }

        $compiled = require $file;

        if (! is_array($compiled)) {
            return null;
        }

        /** @var array<string, ManifestEntry> $compiled */
        $this->memo[$key] = $compiled;

        return $compiled;
    }

    /**
     * @return array<string, ManifestEntry>
     */
    public function compile(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
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

    /**
     * Only this server's file is removed, so other servers serve theirs until paper:warm --compile runs there.
     */
    private function forgetCompiled(string $key): void
    {
        $file = $this->compiledFile($key);

        if ($file === null || ! is_file($file)) {
            return;
        }

        @unlink($file);

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
     * The mtime is compared exactly, not with >=, so a file restored to an older mtime still reparses.
     *
     * @param  ManifestEntry|null  $existing
     * @param  array{mtime: int, ext: string}  $info
     *
     * @phpstan-assert-if-true ManifestEntry $existing
     */
    private function fresh(?array $existing, array $info): bool
    {
        // A second write within the same second keeps this mtime, so it would never be seen.
        if ($existing === null || $info['mtime'] >= time()) {
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
     * @param  array<string, ManifestFile>  $index
     * @return array<string, ManifestEntry>
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
     * @param  array<string, ManifestFile>  $index
     * @param  array<string, ManifestEntry>  $cached
     * @return array<string, ManifestEntry>
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
     * @param  ManifestEntry|null  $existing
     * @param  ManifestFile  $info
     * @return ManifestEntry
     */
    private function entryFor(StorageAdapterContract $adapter, DriverContract $driver, ?array $existing, array $info): array
    {
        if ($this->fresh($existing, $info)) {
            return $existing;
        }

        $read = BestEffortWriter::of($adapter)->readVersioned($info['path']);

        if ($read === null) {
            throw FileParseException::unreadable($info['path']);
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
    public function record(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, string $slug, bool $nested = false): ?array
    {
        return $this->recordsFor($adapter, $driver, $contentPath, [$slug], $nested)[$slug] ?? null;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, ManifestRecord>
     */
    public function recordsFor(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, array $slugs, bool $nested = false): array
    {
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
                $existing = $parsed[$slug] = $this->entryFor($adapter, $driver, $existing, $info);
            }

            $found[$slug] = ['slug' => $slug, ...$existing];
        }

        if ($parsed === []) {
            return $found;
        }

        // The lock protects caching the entries, not the records, so a timeout only costs the next reader a parse.
        $this->locked($key, function () use ($key, $parsed, $revision): void {
            $entries = array_replace($this->shared($key) ?? [], $parsed);

            ksort($entries, SORT_STRING);

            $this->store($key, $entries, $revision);
        });

        return $found;
    }

    /**
     * Read straight from the file, because the manifest carries frontmatter only.
     */
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
    public function slugs(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
    {
        $key = $this->key($adapter, $driver, $contentPath, $nested);

        $trusted = $this->trusted($key);

        if ($trusted !== null) {
            return array_map(strval(...), array_keys($trusted));
        }

        $index = $this->files($adapter, $driver, $contentPath, $nested);

        return array_map(strval(...), array_keys($index));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, string $slug, string $path, array $data, string $version, bool $nested = false): void
    {
        $info = [
            'path' => $path,
            'mtime' => $adapter->lastModified($path) ?? 0,
            'ext' => pathinfo($path, PATHINFO_EXTENSION),
        ];

        $this->mutate($this->key($adapter, $driver, $contentPath, $nested), function (array $entries) use ($driver, $slug, $info, $data, $version): array {
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

        return ['mtime' => $info['mtime'], 'ext' => $info['ext'], 'data' => $data, 'version' => $version];
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

    /**
     * The revision moves first, so a rebuild under way cannot pass its check and bury this invalidation.
     */
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
    public function files(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested = false): array
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
     * @return array<string, ManifestEntry>|null
     */
    private function read(string $key): ?array
    {
        return $this->memo[$key] ?? $this->shared($key);
    }

    /**
     * Reads past the memo, so a rebuild sees what other processes stored.
     *
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
     * The revision is checked on both sides of the write, because the cache has no compare-and-set.
     *
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

    private function key(StorageAdapterContract $adapter, DriverContract $driver, string $contentPath, bool $nested): string
    {
        $scope = $this->version.':'.$adapter->cacheKey($contentPath).':'.$driver::class.':'.($nested ? 'nested' : 'flat');

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
