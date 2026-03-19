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
    public function getIfFresh(string $filepath): ?array
    {
        $mtime = @filemtime($filepath);

        if ($mtime === false) {
            return null;
        }

        $cached = $this->cache->get($this->key($filepath));

        if (! is_array($cached) || ($cached['mtime'] ?? 0) < $mtime) {
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

    public function forget(string $filepath): void
    {
        $this->cache->forget($this->key($filepath));
    }

    private function key(string $filepath): string
    {
        return self::PREFIX.md5($filepath);
    }
}
