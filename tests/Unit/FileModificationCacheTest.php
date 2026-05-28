<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\FileModificationCache;

beforeEach(function (): void {
    $this->repository = new Repository(new ArrayStore);
    $this->cache = new FileModificationCache($this->repository);
    $this->filepath = tempnam(sys_get_temp_dir(), 'paper_cache_');
    file_put_contents($this->filepath, 'content');
});

afterEach(function (): void {
    @unlink($this->filepath);
});

it('serves repeat reads from memo without consulting the underlying repository', function (): void {
    $this->cache->set($this->filepath, ['title' => 'memoed'], (int) filemtime($this->filepath));

    $this->repository->flush();

    expect($this->cache->getIfFresh($this->filepath))->toBe(['title' => 'memoed']);
});

it('populates memo from the underlying repository on first read', function (): void {
    $this->cache->set($this->filepath, ['title' => 'persisted'], (int) filemtime($this->filepath));

    $fresh = new FileModificationCache($this->repository);
    $fresh->getIfFresh($this->filepath);

    $this->repository->flush();

    expect($fresh->getIfFresh($this->filepath))->toBe(['title' => 'persisted']);
});

it('invalidates memo when the file is newer than the memoed entry', function (): void {
    $mtime = (int) filemtime($this->filepath);
    $this->cache->set($this->filepath, ['title' => 'stale'], $mtime);

    touch($this->filepath, $mtime + 60);

    expect($this->cache->getIfFresh($this->filepath))->toBeNull();
});

it('clears the memo on forget so save invalidation flows through', function (): void {
    $this->cache->set($this->filepath, ['title' => 'memoed'], (int) filemtime($this->filepath));
    $this->cache->forget($this->filepath);

    expect($this->cache->getIfFresh($this->filepath))->toBeNull();
});
