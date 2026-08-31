<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Drivers\YamlDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingStore;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RacingStore;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TimestampedPost;

beforeEach(function (): void {
    $this->manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $this->adapter = new CountingAdapter;

    for ($i = 1; $i <= 5; $i++) {
        $this->adapter->seed("blog/post-{$i}.md", "---\nstatus: published\n---\nbody {$i}", 1_000 + $i);
    }

    $this->build = fn (string $model = Post::class): PaperQueryBuilder => new PaperQueryBuilder(
        $this->adapter, new MarkdownDriver, $this->manifest, 'blog', $model,
    );
});

it('reads every file once, then serves warm queries from the manifest', function (): void {
    $cold = ($this->build)()->where('status', 'published')->get();

    expect($cold)->toHaveCount(5)
        ->and($this->adapter->counts['listing'])->toBe(1)
        ->and($this->adapter->counts['read'])->toBe(5);

    $this->adapter->reset();

    $warm = ($this->build)()->where('status', 'published')->get();

    expect($warm)->toHaveCount(5)
        ->and($this->adapter->counts['listing'])->toBe(1)
        ->and($this->adapter->counts['read'])->toBe(0);
});

it('reads only the requested file on a cold find, then serves it warm', function (): void {
    $cold = ($this->build)()->find('post-3');

    expect($cold?->status)->toBe('published')
        ->and($this->adapter->counts['listing'])->toBe(1)
        ->and($this->adapter->counts['read'])->toBe(1);

    $this->adapter->reset();

    $warm = ($this->build)()->find('post-3');

    expect($warm?->status)->toBe('published')
        ->and($this->adapter->counts['listing'])->toBe(1)
        ->and($this->adapter->counts['read'])->toBe(0);
});

it('re-reads only the file whose mtime differs from the cached entry', function (int $mtime): void {
    ($this->build)()->get();

    $this->adapter->reset();
    $this->adapter->seed('blog/post-3.md', "---\nstatus: draft\n---\nedited", $mtime);

    $models = ($this->build)()->get();

    expect($this->adapter->counts['listing'])->toBe(1)
        ->and($this->adapter->counts['read'])->toBe(1)
        ->and($models->firstWhere('slug', 'post-3')->status)->toBe('draft');
})->with([
    'edited, so newer' => 9_999,
    'restored from backup, so older' => 500,
]);

it('drops a deleted file from results without reading anything', function (): void {
    ($this->build)()->get();

    $this->adapter->reset();
    $this->adapter->remove('blog/post-2.md');

    $models = ($this->build)()->get();

    expect($models)->toHaveCount(4)
        ->and($this->adapter->counts['read'])->toBe(0)
        ->and($models->pluck('slug')->all())->not->toContain('post-2');
});

it('counts without reading any file when there is no filter', function (): void {
    $count = ($this->build)()->count();

    expect($count)->toBe(5)
        ->and($this->adapter->counts['listing'])->toBe(1)
        ->and($this->adapter->counts['read'])->toBe(0);
});

it('orders by updated_at using the manifest mtime, not the filesystem', function (): void {
    $this->adapter = new CountingAdapter;
    $this->adapter->seed('blog/alpha.md', "---\n---\na", 3_000);
    $this->adapter->seed('blog/beta.md', "---\n---\nb", 1_000);
    $this->adapter->seed('blog/gamma.md', "---\n---\nc", 2_000);

    $latest = ($this->build)(TimestampedPost::class)->orderBy('updated_at', 'desc')->get();

    expect($latest->pluck('slug')->all())->toBe(['alpha', 'gamma', 'beta']);
});

it('serves later queries in a request from the manifest it already read', function (): void {
    $store = new CountingStore;
    $manifest = new PaperManifest(new Repository($store), 60, 10, false);

    $build = fn (): PaperQueryBuilder => new PaperQueryBuilder(
        $this->adapter, new MarkdownDriver, $manifest, 'blog', Post::class,
    );

    $build()->get();
    $store->reset();

    $build()->find('post-3');
    $build()->where('status', 'published')->get();
    $build()->count();

    expect($store->counts['get'])->toBe(0);
});

it('drops what it memoized between requests, so a cleared cache is noticed', function (): void {
    $driver = new MarkdownDriver;
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    app(PaperManifest::class)->record($adapter, $driver, 'blog', 'post-1');

    Cache::flush();
    $adapter->reset();
    $this->app->forgetScopedInstances();

    app(PaperManifest::class)->record($adapter, $driver, 'blog', 'post-1');

    expect($adapter->counts['read'])->toBe(1);
});

it('fails loudly when a listed file cannot be read instead of yielding a blank record', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/ghost.md', "---\ntitle: Ghost\n---\n", 1_000);
    $adapter->failRead = true;

    $builder = new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawModel::class);

    expect(fn (): Collection => $builder->get())
        ->toThrow(FileParseException::class, 'Failed to read file')
        ->and($builder->validateFiles()['failures'])->toHaveCount(1);
});

it('keeps one manifest per driver when two models read the same directory', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, false);
    $adapter = new CountingAdapter;
    $adapter->seed('content/about.md', "---\nsource: markdown\n---\n", 1_000);
    $adapter->seed('content/about.json', '{"source": "json"}', 1_000);

    $markdown = $manifest->records($adapter, new MarkdownDriver, 'content');
    $json = $manifest->records($adapter, new JsonDriver, 'content');

    expect($markdown['about']['data']['source'])->toBe('markdown')
        ->and($json['about']['data']['source'])->toBe('json');
});

it('keeps one manifest per nesting mode when two models read the same directory', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, false);
    $adapter = new CountingAdapter;
    $adapter->seed('docs/index.md', "---\ntitle: Index\n---\n", 1_000);
    $adapter->seed('docs/guides/installation.md', "---\ntitle: Installation\n---\n", 1_000);

    $flat = $manifest->records($adapter, new MarkdownDriver, 'docs');
    $nested = $manifest->records($adapter, new MarkdownDriver, 'docs', nested: true);

    expect(array_keys($flat))->toBe(['index'])
        ->and(array_keys($nested))->toBe(['guides/installation', 'index']);
});

it('reparses a slug whose file swaps extension at the same modification time', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('data/config.yml', "source: yml\n", 1_000);

    $manifest->records($adapter, new YamlDriver, 'data');

    $adapter->remove('data/config.yml');
    $adapter->seed('data/config.yaml', "source: yaml\n", 1_000);

    $records = $manifest->records($adapter, new YamlDriver, 'data');

    expect($records['config']['data']['source'])->toBe('yaml');
});

it('keeps a record another process added while it writes its own', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $first = new PaperManifest($cache, 60, 10, false);
    $second = new PaperManifest($cache, 60, 10, false);

    $first->records($adapter, new MarkdownDriver, 'blog');
    $second->records($adapter, new MarkdownDriver, 'blog');

    $first->put($adapter, new MarkdownDriver, 'blog', 'post-2', 'blog/post-2.md', ['title' => 'Two']);
    $second->put($adapter, new MarkdownDriver, 'blog', 'post-3', 'blog/post-3.md', ['title' => 'Three']);

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($adapter, new MarkdownDriver, 'blog')))
        ->toBe(['post-1', 'post-2', 'post-3']);
});

it('reparses an entry the listing missed when its file changed', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);
    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);

    $manifest = new PaperManifest($cache, 60, 10, false);
    $manifest->records($adapter, new MarkdownDriver, 'blog');

    $adapter->seed('blog/post-2.md', "---\ntitle: Rewritten\n---\n", 2_500);
    $adapter->hideFromListing('blog/post-2.md');

    $records = $manifest->reconcile($adapter, new MarkdownDriver, 'blog');

    expect($records['post-2']['data']['title'])->toBe('Rewritten')
        ->and($records['post-2']['mtime'])->toBe(2_500);
});

it('keeps an entry another process stored while the rebuild was parsing', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 10, false);
    $rebuilding->records($adapter, new MarkdownDriver, 'blog');

    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
    $adapter->hideFromListing('blog/post-2.md');

    $saving = new PaperManifest($cache, 60, 10, false);
    $saving->put($adapter, new MarkdownDriver, 'blog', 'post-2', 'blog/post-2.md', ['title' => 'Two']);

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);
    $rebuilding->reconcile($adapter, new MarkdownDriver, 'blog');

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($adapter, new MarkdownDriver, 'blog')))->toBe(['post-1', 'post-2']);
});

it('keeps a record another process saved while it caches a single file', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);
    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);

    $reading = new PaperManifest($cache, 60, 10, true);
    $reading->records($adapter, new MarkdownDriver, 'blog');

    $adapter->seed('blog/post-3.md', "---\ntitle: Three\n---\n", 3_000);

    $saving = new PaperManifest($cache, 60, 10, true);
    $saving->put($adapter, new MarkdownDriver, 'blog', 'post-3', 'blog/post-3.md', ['title' => 'Three']);

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);
    $reading->record($adapter, new MarkdownDriver, 'blog', 'post-1');

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($adapter, new MarkdownDriver, 'blog')))
        ->toBe(['post-1', 'post-2', 'post-3']);
});

it('drops the manifest rather than merge a save into it without the lock', function (): void {
    $store = new RacingStore;
    $cache = new Repository($store);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $saving = new PaperManifest($cache, 60, 0, false);
    $saving->records($adapter, new MarkdownDriver, 'blog');

    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
    $store->contended = true;

    $saving->put($adapter, new MarkdownDriver, 'blog', 'post-2', 'blog/post-2.md', ['title' => 'Two']);

    $store->contended = false;
    $adapter->reset();

    $reader = new PaperManifest($cache, 60, 0, false);

    expect(array_keys($reader->records($adapter, new MarkdownDriver, 'blog')))->toBe(['post-1', 'post-2'])
        ->and($adapter->counts['listing'])->toBe(1);
});

it('serves a rebuild it could not lock without storing it', function (): void {
    $store = new RacingStore;
    $cache = new Repository($store);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 0, false);
    $rebuilding->records($adapter, new MarkdownDriver, 'blog');

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);
    $store->contended = true;

    $records = $rebuilding->reconcile($adapter, new MarkdownDriver, 'blog');

    $store->contended = false;

    $reader = new PaperManifest($cache, 60, 0, false);

    expect($records['post-1']['data']['title'])->toBe('Edited')
        ->and($reader->records($adapter, new MarkdownDriver, 'blog')['post-1']['data']['title'])->toBe('One');
});

it('keeps a record another process deleted while it writes its own', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);
    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);

    $first = new PaperManifest($cache, 60, 10, false);
    $second = new PaperManifest($cache, 60, 10, false);

    $first->records($adapter, new MarkdownDriver, 'blog');
    $second->records($adapter, new MarkdownDriver, 'blog');

    $first->forget($adapter, new MarkdownDriver, 'blog', 'post-1');
    $second->put($adapter, new MarkdownDriver, 'blog', 'post-3', 'blog/post-3.md', ['title' => 'Three']);

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($adapter, new MarkdownDriver, 'blog')))
        ->toBe(['post-2', 'post-3']);
});

it('does not publish a rebuild over a save that could not wait for it', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 0, false);
    $rebuilding->records($adapter, new MarkdownDriver, 'blog');

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);

    $saving = new PaperManifest($cache, 60, 0, false);

    $adapter->duringNextRead(function () use ($adapter, $saving): void {
        $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
        $saving->put($adapter, new MarkdownDriver, 'blog', 'post-2', 'blog/post-2.md', ['title' => 'Two']);
    });

    $rebuilding->reconcile($adapter, new MarkdownDriver, 'blog');

    $reader = new PaperManifest($cache, 60, 0, false);

    expect(array_keys($reader->records($adapter, new MarkdownDriver, 'blog')))->toBe(['post-1', 'post-2']);
});

it('takes back a manifest invalidated between the revision check and the write', function (): void {
    $store = new RacingStore;
    $cache = new Repository($store);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 0, false);
    $rebuilding->records($adapter, new MarkdownDriver, 'blog');

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);

    $saving = new PaperManifest($cache, 60, 0, false);

    $store->duringNextWrite(function () use ($adapter, $saving): void {
        $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
        $saving->put($adapter, new MarkdownDriver, 'blog', 'post-2', 'blog/post-2.md', ['title' => 'Two']);
    });

    $rebuilding->reconcile($adapter, new MarkdownDriver, 'blog');

    $reader = new PaperManifest($cache, 60, 0, false);

    expect(array_keys($reader->records($adapter, new MarkdownDriver, 'blog')))->toBe(['post-1', 'post-2']);
});
