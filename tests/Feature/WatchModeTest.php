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

it('watches locally and trusts the manifest elsewhere when watch is auto', function (): void {
    $listingsOnWarmQuery = function (string $environment): int {
        config(['paper.watch' => 'auto']);
        app()->detectEnvironment(fn (): string => $environment);
        app()->forgetInstance(PaperManifest::class);

        $manifest = app(PaperManifest::class);
        $adapter = new CountingAdapter;
        $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

        $manifest->records($adapter, new MarkdownDriver, 'blog');
        $adapter->reset();
        $manifest->records($adapter, new MarkdownDriver, 'blog');

        return $adapter->counts['listing'];
    };

    expect($listingsOnWarmQuery('local'))->toBe(1)
        ->and($listingsOnWarmQuery('production'))->toBe(0);
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
    $this->manifest->flush($adapter, $this->driver, 'blog');
    $adapter->reset();

    $records = $this->manifest->records($adapter, $this->driver, 'blog');

    expect($records)->toHaveCount(1)
        ->and($adapter->counts['listing'])->toBe(1);
});

it('reconciles against the disk even when the manifest is trusted', function (): void {
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $this->manifest->records($adapter, $this->driver, 'blog');

    $adapter->seed('blog/post-2.md', "---\nstatus: published\n---\n", 2_000);

    expect($this->manifest->records($adapter, $this->driver, 'blog'))->toHaveCount(1)
        ->and($this->manifest->reconcile($adapter, $this->driver, 'blog'))->toHaveCount(2);
});
