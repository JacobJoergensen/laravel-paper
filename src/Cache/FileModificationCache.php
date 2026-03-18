<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Cache;

use Illuminate\Contracts\Cache\Repository;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use Psr\SimpleCache\InvalidArgumentException;

final class FileModificationCache implements CacheContract
{
    private const string PREFIX = 'paper:';

    public function __construct(
        private readonly Repository $cache,
    ) {}

    /**
     * @return ?array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function get(string $filepath): ?array
    {
        $cached = $this->cache->get($this->key($filepath));

        if (! is_array($cached)) {
            return null;
        }

        /** @var ?array<string, mixed> */
        return $cached['data'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function set(string $filepath, array $data, int $mtime): void
    {
        $this->cache->forever($this->key($filepath), [
            'mtime' => $mtime,
            'data' => $data,
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function isStale(string $filepath): bool
    {
        if (! file_exists($filepath)) {
            return true;
        }

        $cached = $this->cache->get($this->key($filepath));

        if (! is_array($cached) || ! isset($cached['mtime'])) {
            return true;
        }

        return filemtime($filepath) > $cached['mtime'];
    }

    public function forget(string $filepath): void
    {
        $this->cache->forget($this->key($filepath));
    }

    private function key(string $filepath): string
    {
        return self::PREFIX.md5($filepath);
    }
}
