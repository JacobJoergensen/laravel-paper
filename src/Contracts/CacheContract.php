<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

interface CacheContract
{
    /**
     * @return ?array<string, mixed>
     */
    public function getIfFresh(string $filepath): ?array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function set(string $filepath, array $data, int $mtime): void;

    public function forget(string $filepath): void;
}
