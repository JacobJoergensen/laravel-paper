<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;

it('keeps the manifest on the configured store, so clearing the default cache leaves it intact', function (): void {
    config(['cache.stores.paper_dedicated' => ['driver' => 'array']]);
    config(['paper.cache_store' => 'paper_dedicated']);
    $this->app->forgetInstance(PaperManifest::class);

    $manifest = app(PaperManifest::class);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    $manifest->record($adapter, new MarkdownDriver, 'blog', 'post-1');

    Cache::flush();
    $adapter->reset();

    $entry = $manifest->record($adapter, new MarkdownDriver, 'blog', 'post-1');

    expect($entry)->not->toBeNull()
        ->and($adapter->counts['read'])->toBe(0);
});
