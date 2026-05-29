<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\FileModificationCache;

beforeEach(function (): void {
    $this->repository = new Repository(new ArrayStore);
    $this->cache = new FileModificationCache($this->repository);
});

it('serves repeat reads from memo without consulting the underlying repository', function (): void {
    $this->cache->set('post.md', ['title' => 'memoed'], 100);

    $this->repository->flush();

    expect($this->cache->getIfFresh('post.md', 100))->toBe(['title' => 'memoed']);
});

it('populates memo from the underlying repository on first read', function (): void {
    $this->cache->set('post.md', ['title' => 'persisted'], 100);

    $fresh = new FileModificationCache($this->repository);
    $fresh->getIfFresh('post.md', 100);

    $this->repository->flush();

    expect($fresh->getIfFresh('post.md', 100))->toBe(['title' => 'persisted']);
});

it('invalidates memo when the requested mtime is newer than the memoed entry', function (): void {
    $this->cache->set('post.md', ['title' => 'stale'], 100);

    expect($this->cache->getIfFresh('post.md', 200))->toBeNull();
});

it('clears the memo on forget so save invalidation flows through', function (): void {
    $this->cache->set('post.md', ['title' => 'memoed'], 100);
    $this->cache->forget('post.md');

    expect($this->cache->getIfFresh('post.md', 100))->toBeNull();
});
