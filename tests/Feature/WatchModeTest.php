<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;

beforeEach(function (): void {
    $this->manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, false);
    $this->driver = new MarkdownDriver;
});

it('serves a warm manifest without a directory listing when the watcher is off', function (): void {
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $this->manifest->records($adapter, $this->driver, 'blog');
    $adapter->reset();

    $records = $this->manifest->records($adapter, $this->driver, 'blog');

    expect($records)->toHaveCount(1)
        ->and($adapter->counts['listing'])->toBe(0)
        ->and($adapter->counts['read'])->toBe(0);
});

it('trusts an empty manifest instead of listing again to rediscover it', function (): void {
    $adapter = new CountingAdapter;

    $this->manifest->records($adapter, $this->driver, 'blog');
    $adapter->reset();

    $records = $this->manifest->records($adapter, $this->driver, 'blog');

    expect($records)->toBe([])
        ->and($adapter->counts['listing'])->toBe(0);
});

it('rebuilds from a listing when the cached manifest is gone', function (): void {
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $this->manifest->records($adapter, $this->driver, 'blog');
    $this->manifest->flush($adapter, 'blog');
    $adapter->reset();

    $records = $this->manifest->records($adapter, $this->driver, 'blog');

    expect($records)->toHaveCount(1)
        ->and($adapter->counts['listing'])->toBe(1);
});
