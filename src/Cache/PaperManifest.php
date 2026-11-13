<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Closure;
use ErrorException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JacobJoergensen\LaravelPaper\ContentSource;
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\ManifestCacheException;
use JacobJoergensen\LaravelPaper\StorageAdapters\BestEffortWriter;
use JacobJoergensen\LaravelPaper\StorageAdapters\LocalAdapter;

/**
 * @internal
 *
 * @phpstan-type ManifestEntry array{slug: string, mtime: int, parsed: int, ext: string, data: array<string, mixed>, version: string}
 * @phpstan-type ManifestFile array{path: string, mtime: int, ext: string}
 */
final class PaperManifest
{
    private const string PREFIX = 'paper:manifest:';

    /**
     * @var array<string, array<string, ManifestEntry>>
     */
    private array $memo = [];

    /**
     * @var array<string, array<string, ManifestFile>>
     */
    private array $listings = [];

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
    public function records(ContentSource $source): array
    {
        $key = $this->key($source);

        return $this->trusted($key) ?? $this->rebuild($source, $key);
    }

    /**
     * @return array<string, ManifestEntry>
     */
    public function reconcile(ContentSource $source): array
    {
        $key = $this->key($source);

        unset($this->listings[$key]);

        return $this->rebuild($source, $key);
    }

    /**
     * @return array<string, ManifestEntry>
     */
    private function rebuild(ContentSource $source, string $key): array
    {
        $revision = $this->revision($key);
        $index = $this->listing($source, $key);
        $cached = $this->read($key);

        if ($cached !== null && $this->current($cached, $index)) {
            return $cached;
        }

        $rebuild = function () use ($source, $index, $key, $revision): array {
            $shared = $this->shared($key);

            if ($shared !== null && $this->current($shared, $index)) {
                return $shared;
            }

            $entries = $this->entries($source, $index, $shared ?? []);

            $this->store($key, $entries, $revision);

            return $entries;
        };

        $rebuilt = $this->locked($key, $rebuild);

        return $rebuilt ?? $this->entries($source, $index, $cached ?? []);
    }

    /**
     * @return array<string, ManifestEntry>|null
     */
    private function trusted(string $key): ?array
    {
        if ($this->watch) {
            return null;
        }

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $file = $this->opcache ? $this->compiledFile($key) : null;
        $compiled = $file !== null ? @include $file : null;

        if (is_array($compiled)) {
            /** @var array<string, ManifestEntry> $compiled */
            return $this->memo[$key] = $compiled;
        }

        $entries = $this->read($key);

        if ($file !== null && $entries !== null) {
            $this->compile($key, $file, $entries);
        }

        return $entries;
    }

    /**
     * @param  array<string, ManifestEntry>  $entries
     */
    private function compile(string $key, string $file, array $entries): void
    {
        $files = new Filesystem;
        $directory = dirname($file);

        try {
            $files->ensureDirectoryExists($directory);

            if (! is_writable($directory)) {
                return;
            }

            $files->replace($file, '<?php return '.var_export($entries, true).';'.PHP_EOL);

            $protection = (int) ini_get('opcache.file_update_protection');
            touch($file, time() - $protection - 1);

            $this->forgetCompiled($key, $file);
        } catch (ErrorException) {
        }
    }

    private function forgetCompiled(string $key, ?string $keep = null): void
    {
        if ($this->compiledPath === null) {
            return;
        }

        $pattern = $this->compiledPrefix($key).'*.php';
        $stale = array_diff(glob($pattern) ?: [], [$keep]);

        foreach ($stale as $file) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }

            @unlink($file);
        }
    }

    private function compiledFile(string $key): ?string
    {
        $generation = $this->cache->get($key.':generation');

        if ($this->compiledPath === null || ! is_string($generation)) {
            return null;
        }

        return $this->compiledPrefix($key).$generation.'.php';
    }

    private function compiledPrefix(string $key): string
    {
        return $this->compiledPath.'/'.str_replace(':', '-', $key).'-';
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
     * @param  ManifestFile  $info
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
    private function entries(ContentSource $source, array $index, array $cached): array
    {
        $entries = [];

        foreach ($index as $slug => $info) {
            $entry = $this->entryFor($source, (string) $slug, $cached[$slug] ?? null, $info);

            if ($entry !== null) {
                $entries[$slug] = $entry;
            }
        }

        foreach ($cached as $slug => $cachedEntry) {
            if (isset($entries[$slug])) {
                continue;
            }

            $path = $source->path.'/'.$slug.'.'.$cachedEntry['ext'];
            $mtime = $source->adapter->lastModified($path);

            if ($mtime === null) {
                continue;
            }

            $info = ['path' => $path, 'mtime' => $mtime, 'ext' => $cachedEntry['ext']];
            $entry = $this->entryFor($source, (string) $slug, $cachedEntry, $info);

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
    private function entryFor(ContentSource $source, string $slug, ?array $existing, array $info): ?array
    {
        if ($this->fresh($existing, $info)) {
            return $existing;
        }

        $read = BestEffortWriter::of($source->adapter)->readVersioned($info['path']);

        if ($read === null && $source->adapter->exists($info['path'])) {
            throw FileParseException::unreadable($info['path']);
        }

        if ($read === null) {
            return null;
        }

        $data = $this->parse($source->driver, $info['path'], $read['contents']);

        return $this->entry($source->driver, $slug, $info, $data, $read['version']);
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
     * @return ManifestEntry|null
     */
    public function record(ContentSource $source, string $slug): ?array
    {
        return $this->recordsFor($source, [$slug])[$slug] ?? null;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, ManifestEntry>
     */
    public function recordsFor(ContentSource $source, array $slugs): array
    {
        $found = [];

        if (! $this->watch) {
            $entries = $this->records($source);

            foreach ($slugs as $slug) {
                if (isset($entries[$slug])) {
                    $found[$slug] = $entries[$slug];
                }
            }

            return $found;
        }

        $key = $this->key($source);
        $revision = null;
        $adapter = $source->adapter;
        $direct = $adapter instanceof LocalAdapter && ! isset($this->listings[$key]);

        if ($direct) {
            $extensions = $source->driver->extensions();
            $index = $adapter->locate($source->path, $slugs, $extensions, $source->nested);
            $cached = $this->memo[$key] ?? [];
        } else {
            $revision = $this->revision($key);
            $index = $this->listing($source, $key);
            $cached = $this->read($key) ?? [];
        }

        $parsed = [];

        foreach ($slugs as $slug) {
            $info = $index[$slug] ?? null;

            if ($info === null) {
                continue;
            }

            $existing = $cached[$slug] ?? null;

            if (! $this->fresh($existing, $info)) {
                $existing = $this->entryFor($source, $slug, $existing, $info);

                if ($existing === null) {
                    continue;
                }

                $parsed[$slug] = $existing;
            }

            $found[$slug] = $existing;
        }

        if ($parsed === [] || $direct) {
            return $found;
        }

        $this->locked($key, function () use ($key, $parsed, $revision): void {
            $entries = array_replace($this->shared($key) ?? [], $parsed);

            ksort($entries, SORT_STRING);

            $this->store($key, $entries, $revision);
        });

        return $found;
    }

    public function body(ContentSource $source, string $path): ?string
    {
        $contents = $source->adapter->read($path);

        return $contents === null ? null : $source->driver->body($contents);
    }

    /**
     * @return list<string>
     */
    public function slugs(ContentSource $source): array
    {
        $key = $this->key($source);
        $entries = $this->trusted($key) ?? $this->listing($source, $key);

        return array_map(strval(...), array_keys($entries));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(ContentSource $source, string $slug, string $path, array $data, string $version): void
    {
        $info = [
            'path' => $path,
            'mtime' => $source->adapter->lastModified($path) ?? 0,
            'ext' => pathinfo($path, PATHINFO_EXTENSION),
        ];

        $key = $this->key($source);

        $this->mutate($key, function (array $entries) use ($source, $slug, $info, $data, $version): array {
            $entries[$slug] = $this->entry($source->driver, $slug, $info, $data, $version);

            ksort($entries, SORT_STRING);

            return $entries;
        });
    }

    /**
     * @param  ManifestFile  $info
     * @param  array<string, mixed>  $data
     * @return ManifestEntry
     */
    private function entry(DriverContract $driver, string $slug, array $info, array $data, string $version): array
    {
        $column = $driver->bodyColumn();

        if ($column !== null) {
            unset($data[$column]);
        }

        array_walk_recursive($data, function (mixed $value) use ($info): void {
            if (is_object($value)) {
                $error = FileParseException::objectValue(get_debug_type($value));

                throw FileParseException::inFile($info['path'], $error);
            }
        });

        return [
            'slug' => $slug,
            'mtime' => $info['mtime'],
            'parsed' => now()->getTimestamp(),
            'ext' => $info['ext'],
            'data' => $data,
            'version' => $version,
        ];
    }

    public function forget(ContentSource $source, string $slug): void
    {
        $key = $this->key($source);

        $this->mutate($key, function (array $entries) use ($slug): array {
            unset($entries[$slug]);

            return $entries;
        });
    }

    public function flush(ContentSource $source): void
    {
        $this->invalidate($this->key($source));
    }

    /**
     * @param  Closure(array<string, ManifestEntry>): array<string, ManifestEntry>  $change
     */
    private function mutate(string $key, Closure $change): void
    {
        $this->listings = [];

        $merged = $this->locked($key, function () use ($key, $change): array {
            $revision = $this->revision($key);
            $cached = $this->shared($key);

            if ($cached === null) {
                $this->drop($key);

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
        $this->listings = [];

        $this->cache->forever($key.':revision', Str::random());

        $this->drop($key);
    }

    private function drop(string $key): void
    {
        $this->cache->forget($key.':generation');
        $this->forgetCompiled($key);

        unset($this->memo[$key]);

        $this->cache->forget($key);
    }

    private function revision(string $key): ?string
    {
        $revision = $this->cache->get($key.':revision');

        return is_string($revision) ? $revision : null;
    }

    /**
     * @param  array<string, int>  $listing
     * @return array<string, ManifestFile>
     */
    public function index(ContentSource $source, array $listing): array
    {
        $priority = array_flip($source->driver->extensions());
        $root = rtrim(str_replace('\\', '/', $source->path), '/').'/';
        $byslug = [];

        foreach ($listing as $path => $mtime) {
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
     * @return array<string, ManifestFile>
     */
    private function listing(ContentSource $source, string $key): array
    {
        if (isset($this->listings[$key])) {
            return $this->listings[$key];
        }

        $listed = $source->adapter->listing($source->path, $source->driver->extensions(), $source->nested);

        return $this->listings[$key] = $this->index($source, $listed);
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

        $this->cache->forever($key.':generation', Str::random(16));

        if ($this->revision($key) !== $revision) {
            $this->drop($key);
        }
    }

    private function key(ContentSource $source): string
    {
        $scope = implode(':', [
            $this->version,
            $source->adapter->cacheKey($source->path),
            $source->driver::class,
            $source->nested ? 'nested' : 'flat',
            $this->watch ? 'watched' : 'trusted',
        ]);

        return self::PREFIX.md5($scope);
    }
}
