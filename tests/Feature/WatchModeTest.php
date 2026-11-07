<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\ContentSource;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    $this->manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, false);
});

it('watches locally and trusts the manifest elsewhere when watch is auto', function (): void {
    $listingsOnWarmQuery = function (string $environment): int {
        config(['paper.watch' => 'auto']);
        app()->detectEnvironment(fn (): string => $environment);
        app()->forgetInstance(PaperManifest::class);

        $adapter = new CountingAdapter;
        $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
        $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

        app(PaperManifest::class)->records($source);
        $adapter->reset();
        app()->forgetScopedInstances();
        app(PaperManifest::class)->records($source);

        return $adapter->counts['listing'];
    };

    expect($listingsOnWarmQuery('local'))->toBe(1)
        ->and($listingsOnWarmQuery('production'))->toBe(0);
});

it('sees a file added outside the app on the next request, not later in the same one', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $request = new PaperManifest($cache, 60, 10, true);
    $request->records($source);

    $adapter->seed('blog/post-2.md', "---\nstatus: published\n---\n", 2_000);

    expect($request->records($source))->toHaveCount(1)
        ->and(new PaperManifest($cache, 60, 10, true)->records($source))->toHaveCount(2);
});

it('sees a record another model saved into the same directory later in the request', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $flat = new ContentSource($adapter, new MarkdownDriver, 'docs', false);
    $nested = new ContentSource($adapter, new MarkdownDriver, 'docs', true);
    $adapter->seed('docs/index.md', "---\ntitle: Index\n---\n", 1_000);

    $manifest->records($nested);

    $adapter->seed('docs/new.md', "---\ntitle: New\n---\n", 2_000);
    $manifest->put($flat, 'new', 'docs/new.md', ['title' => 'New'], 'v1');

    expect(array_keys($manifest->records($nested)))->toBe(['index', 'new']);
});

it('serves a warm manifest without a directory listing when the watcher is off', function (): void {
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $this->manifest->records($source);
    $adapter->reset();

    $records = $this->manifest->records($source);

    expect($records)->toHaveCount(1)
        ->and($adapter->counts['listing'])->toBe(0)
        ->and($adapter->counts['read'])->toBe(0);
});

it('trusts an empty manifest instead of listing again to rediscover it', function (): void {
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);

    $this->manifest->records($source);
    $adapter->reset();

    $records = $this->manifest->records($source);

    expect($records)->toBe([])
        ->and($adapter->counts['listing'])->toBe(0);
});

it('rebuilds from a listing when the cached manifest is gone', function (): void {
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $this->manifest->records($source);
    $this->manifest->flush($source);
    $adapter->reset();

    $records = $this->manifest->records($source);

    expect($records)->toHaveCount(1)
        ->and($adapter->counts['listing'])->toBe(1);
});

it('reconciles against the disk even when the manifest is trusted', function (): void {
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $this->manifest->records($source);

    $adapter->seed('blog/post-2.md', "---\nstatus: published\n---\n", 2_000);

    expect($this->manifest->records($source))->toHaveCount(1)
        ->and($this->manifest->reconcile($source))->toHaveCount(2);
});

it('keeps a trusted manifest current through every write without rereading the disk', function (string $write): void {
    config(['paper.watch' => false]);
    app()->forgetInstance(PaperManifest::class);

    $path = PaperQueryBuilder::sourceFor(Post::class)->path;
    $adapter = new CountingAdapter;
    $adapter->seed("$path/kept.md", "---\ntitle: Kept\n---\n", 1_000);
    $adapter->seed("$path/old.md", "---\ntitle: Old\n---\n", 1_000);
    PaperQueryBuilder::fake(Post::class, $adapter);

    Post::all();

    match ($write) {
        'create' => Post::create(['slug' => 'new', 'title' => 'New']),
        'update' => Post::findOrFail('old')->update(['title' => 'Changed']),
        'rename' => Post::findOrFail('old')->update(['slug' => 'renamed']),
        'delete' => Post::findOrFail('old')->delete(),
    };

    $adapter->reset();

    expect(Post::pluck('title', 'slug')->all())->toBe(match ($write) {
        'create' => ['kept' => 'Kept', 'new' => 'New', 'old' => 'Old'],
        'update' => ['kept' => 'Kept', 'old' => 'Changed'],
        'rename' => ['kept' => 'Kept', 'renamed' => 'Old'],
        'delete' => ['kept' => 'Kept'],
    })->and($adapter->counts['listing'])->toBe(0)
        ->and($adapter->counts['read'])->toBe(0);
})->with(['create', 'update', 'rename', 'delete']);
