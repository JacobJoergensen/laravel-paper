<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Illuminate\Contracts\Cache\Repository;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use Psr\SimpleCache\InvalidArgumentException;

final class FileModificationCache implements CacheContract
{
    private const string PREFIX = 'paper:';

    /** @var array<string, array{mtime: int, data: array<string, mixed>}> */
    private array $memo = [];

    public function __construct(
        private readonly Repository $cache,
    ) {}

    /**
     * @return ?array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function getIfFresh(string $filepath): ?array
    {
        $mtime = @filemtime($filepath);

        if ($mtime === false) {
            return null;
        }

        $memoed = $this->memo[$filepath] ?? null;

        if ($memoed !== null && $memoed['mtime'] >= $mtime) {
            return $memoed['data'];
        }

        $cached = $this->cache->get($this->key($filepath));

        if (! is_array($cached)) {
            return null;
        }

        $cachedMtime = is_int($cached['mtime'] ?? null) ? $cached['mtime'] : 0;

        if ($cachedMtime < $mtime) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $cached['data'] ?? [];

        $this->memo[$filepath] = [
            'mtime' => $cachedMtime,
            'data' => $data,
        ];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function set(string $filepath, array $data, int $mtime): void
    {
        $this->memo[$filepath] = [
            'mtime' => $mtime,
            'data' => $data,
        ];

        $this->cache->forever($this->key($filepath), [
            'mtime' => $mtime,
            'data' => $data,
        ]);
    }

    public function forget(string $filepath): void
    {
        unset($this->memo[$filepath]);

        $this->cache->forget($this->key($filepath));
    }

    private function key(string $filepath): string
    {
        return self::PREFIX.md5($filepath);
    }
}
