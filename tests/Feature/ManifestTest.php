<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\ContentSource;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Drivers\YamlDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\ManifestCacheException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingStore;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RacingStore;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawModel;

beforeEach(function (): void {
    $this->cache = new Repository(new ArrayStore);
    $this->adapter = new CountingAdapter;
    $this->source = new ContentSource($this->adapter, new MarkdownDriver, 'blog', false);

    for ($i = 1; $i <= 5; $i++) {
        $this->adapter->seed("blog/post-{$i}.md", "---\nstatus: published\n---\nbody {$i}", 1_000 + $i);
    }

    $this->build = fn (string $model = Post::class): PaperQueryBuilder => new PaperQueryBuilder(
        $this->source, new PaperManifest($this->cache, 60, 10, true), $model,
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

it('serves a compiled manifest from opcache without the cache or disk', function (bool $opcache, int $reads): void {
    $compiledPath = sys_get_temp_dir().'/paper-compiled-'.getmypid();

    try {
        new PaperManifest(new Repository(new ArrayStore), 60, 10, false, '', $compiledPath, true)
            ->compile($this->source);
        $this->adapter->reset();

        $records = new PaperManifest(new Repository(new ArrayStore), 60, 10, false, '', $compiledPath, $opcache)
            ->records($this->source);

        expect($records)->toHaveCount(5)
            ->and($this->adapter->counts['read'])->toBe($reads);
    } finally {
        File::deleteDirectory($compiledPath);
    }
})->with([
    'served by opcache' => [true, 0],
    'without opcache' => [false, 5],
]);

it('removes the compiled manifest on a write, so it is never served stale', function (): void {
    $compiledPath = sys_get_temp_dir().'/paper-compiled-'.getmypid();
    $cache = new Repository(new ArrayStore);

    try {
        $manifest = new PaperManifest($cache, 60, 10, false, '', $compiledPath, true);
        $manifest->compile($this->source);

        $this->adapter->remove('blog/post-1.md');
        $manifest->forget($this->source, 'post-1');

        $records = new PaperManifest($cache, 60, 10, false, '', $compiledPath, true)
            ->records($this->source);

        expect($records)->not->toHaveKey('post-1');
    } finally {
        File::deleteDirectory($compiledPath);
    }
});

it('reports a compiled manifest it cannot remove on a write', function (): void {
    Exceptions::fake();

    $compiledPath = sys_get_temp_dir().'/paper-compiled-'.getmypid();

    try {
        $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, false, '', $compiledPath, true);
        $manifest->compile($this->source);

        foreach (glob($compiledPath.'/*.php') ?: [] as $compiled) {
            chmod($compiled, 0444);
        }

        chmod($compiledPath, 0555);

        $manifest->forget($this->source, 'post-1');

        $undeletable = fn (ManifestCacheException $e): bool => str_contains($e->getMessage(), 'could not delete');

        Exceptions::assertReported($undeletable);
    } finally {
        @chmod($compiledPath, 0755);

        foreach (glob($compiledPath.'/*.php') ?: [] as $compiled) {
            chmod($compiled, 0644);
        }

        File::deleteDirectory($compiledPath);
    }
})->skip(function_exists('posix_geteuid') && posix_geteuid() === 0, 'Root ignores file permissions.');

it('reports a manifest the cache store refuses and still answers from the files', function (): void {
    Exceptions::fake();

    $refusing = new class extends ArrayStore
    {
        public function forever($key, $value): bool
        {
            return str_ends_with($key, ':revision') && parent::forever($key, $value);
        }
    };

    $records = new PaperManifest(new Repository($refusing), 60, 10, true)
        ->records($this->source);

    expect($records)->toHaveCount(5);

    Exceptions::assertReported(ManifestCacheException::class);
});

it('reparses every file after a Paper upgrade instead of trusting the old manifest', function (): void {
    $cache = new Repository(new ArrayStore);

    new PaperManifest($cache, 60, 10, false, '1.0.0')->records($this->source);
    $this->adapter->reset();

    new PaperManifest($cache, 60, 10, false, '1.1.0')->records($this->source);

    expect($this->adapter->counts['read'])->toBe(5);
});

it('finds several records with one directory listing', function (): void {
    ($this->build)()->get();
    $this->adapter->reset();

    $found = ($this->build)()->findMany(['post-1', 'post-2', 'post-3']);

    expect($found)->toHaveCount(3)
        ->and($this->adapter->counts['listing'])->toBe(1);
});

it('lists a record first found by slug in slug order', function (): void {
    ($this->build)()->get();
    $this->adapter->seed('blog/post-0.md', "---\nstatus: published\n---\nbody 0", 1_000);

    ($this->build)()->find('post-0');

    expect(($this->build)()->pluck('slug')->first())->toBe('post-0');
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

it('sees a second edit to a file whose modification time is ahead of the clock', function (): void {
    $ahead = time() + 60;

    $this->adapter->seed('blog/post-3.md', "---\nstatus: draft\n---\nfirst", $ahead);
    ($this->build)()->get();

    $this->adapter->seed('blog/post-3.md', "---\nstatus: archived\n---\nsecond", $ahead);
    $models = ($this->build)()->get();

    expect($models->firstWhere('slug', 'post-3')->status)->toBe('archived');
});

it('sees an edit made within the second the file was read, once that second has passed', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(5_000));

    $this->adapter->seed('blog/post-3.md', "---\nstatus: draft\n---\nfirst", 5_000);
    ($this->build)()->get();

    $this->adapter->seed('blog/post-3.md', "---\nstatus: archived\n---\nsecond", 5_000);
    $this->travel(1)->seconds();

    $models = ($this->build)()->get();

    expect($models->firstWhere('slug', 'post-3')->status)->toBe('archived');
});

it('drops a file deleted while the query reads the directory', function (): void {
    $this->adapter->duringNextRead(fn () => $this->adapter->remove('blog/post-2.md'));

    $slugs = ($this->build)()->get()->pluck('slug')->all();

    expect($slugs)->toBe(['post-1', 'post-3', 'post-4', 'post-5']);
});

it('returns every record to a trusting process after a watching one cached a single find', function (): void {
    $cache = new Repository(new ArrayStore);

    $build = fn (bool $watch): PaperQueryBuilder => new PaperQueryBuilder(
        $this->source, new PaperManifest($cache, 60, 10, $watch), Post::class,
    );

    $build(true)->find('post-3');

    expect($build(false)->get())->toHaveCount(5);
});

it('runs queries when the lock timing is set in the environment', function (): void {
    putenv('PAPER_LOCK_TTL=30');
    putenv('PAPER_LOCK_WAIT=5');

    try {
        config(['paper' => require __DIR__.'/../../config/paper.php']);
        $this->app->forgetScopedInstances();

        expect(Post::find('hello-world'))->not->toBeNull();
    } finally {
        putenv('PAPER_LOCK_TTL');
        putenv('PAPER_LOCK_WAIT');
    }
});

it('drops a deleted file from results without reading anything', function (): void {
    ($this->build)()->get();

    $this->adapter->reset();
    $this->adapter->remove('blog/post-2.md');

    $models = ($this->build)()->get();

    expect($models)->toHaveCount(4)
        ->and($this->adapter->counts['read'])->toBe(0)
        ->and($models->pluck('slug')->all())->not->toContain('post-2');
});

it('serves later queries in a request from the manifest it already read', function (): void {
    $store = new CountingStore;
    $manifest = new PaperManifest(new Repository($store), 60, 10, false);

    $build = fn (): PaperQueryBuilder => new PaperQueryBuilder($this->source, $manifest, Post::class);

    $build()->get();
    $store->reset();

    $build()->find('post-3');
    $build()->where('status', 'published')->get();
    $build()->count();

    expect($store->counts['get'])->toBe(0);
});

it('drops what it memoized between requests, so a cleared cache is noticed', function (): void {
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\nstatus: published\n---\n", 1_000);

    app(PaperManifest::class)->record($source, 'post-1');

    Cache::flush();
    $adapter->reset();
    $this->app->forgetScopedInstances();

    app(PaperManifest::class)->record($source, 'post-1');

    expect($adapter->counts['read'])->toBe(1);
});

it('fails loudly when a listed file cannot be read instead of yielding a blank record', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/ghost.md', "---\ntitle: Ghost\n---\n", 1_000);
    $adapter->failRead = true;

    $builder = new PaperQueryBuilder($source, $manifest, RawModel::class);

    expect(fn (): Collection => $builder->get())
        ->toThrow(FileParseException::class, 'Failed to read file')
        ->and($builder->validateFiles()['failures'])->toHaveCount(1);
});

it('keeps one manifest per driver when two models read the same directory', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, false);
    $adapter = new CountingAdapter;
    $adapter->seed('content/about.md', "---\nsource: markdown\n---\n", 1_000);
    $adapter->seed('content/about.json', '{"source": "json"}', 1_000);

    $markdown = $manifest->records(new ContentSource($adapter, new MarkdownDriver, 'content', false));
    $json = $manifest->records(new ContentSource($adapter, new JsonDriver, 'content', false));

    expect($markdown['about']['data']['source'])->toBe('markdown')
        ->and($json['about']['data']['source'])->toBe('json');
});

it('keeps one manifest per nesting mode when two models read the same directory', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, false);
    $adapter = new CountingAdapter;
    $adapter->seed('docs/index.md', "---\ntitle: Index\n---\n", 1_000);
    $adapter->seed('docs/guides/installation.md', "---\ntitle: Installation\n---\n", 1_000);

    $flat = $manifest->records(new ContentSource($adapter, new MarkdownDriver, 'docs', false));
    $nested = $manifest->records(new ContentSource($adapter, new MarkdownDriver, 'docs', true));

    expect(array_keys($flat))->toBe(['index'])
        ->and(array_keys($nested))->toBe(['guides/installation', 'index']);
});

it('reparses a slug whose file swaps extension at the same modification time', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new YamlDriver, 'data', false);
    $adapter->seed('data/config.yml', "source: yml\n", 1_000);

    new PaperManifest($cache, 60, 10, true)->records($source);

    $adapter->remove('data/config.yml');
    $adapter->seed('data/config.yaml', "source: yaml\n", 1_000);

    $records = new PaperManifest($cache, 60, 10, true)->records($source);

    expect($records['config']['data']['source'])->toBe('yaml');
});

it('keeps a record another process added while it writes its own', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $first = new PaperManifest($cache, 60, 10, false);
    $second = new PaperManifest($cache, 60, 10, false);

    $first->records($source);
    $second->records($source);

    $first->put($source, 'post-2', 'blog/post-2.md', ['title' => 'Two'], 'v2');
    $second->put($source, 'post-3', 'blog/post-3.md', ['title' => 'Three'], 'v3');

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($source)))
        ->toBe(['post-1', 'post-2', 'post-3']);
});

it('reparses an entry the listing missed when its file changed', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);
    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);

    $manifest = new PaperManifest($cache, 60, 10, false);
    $manifest->records($source);

    $adapter->seed('blog/post-2.md', "---\ntitle: Rewritten\n---\n", 2_500);
    $adapter->hideFromListing('blog/post-2.md');

    $records = $manifest->reconcile($source);

    expect($records['post-2']['data']['title'])->toBe('Rewritten')
        ->and($records['post-2']['mtime'])->toBe(2_500);
});

it('keeps an entry another process stored while the rebuild was parsing', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 10, false);
    $rebuilding->records($source);

    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
    $adapter->hideFromListing('blog/post-2.md');

    $saving = new PaperManifest($cache, 60, 10, false);
    $saving->put($source, 'post-2', 'blog/post-2.md', ['title' => 'Two'], 'v2');

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);
    $rebuilding->reconcile($source);

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($source)))->toBe(['post-1', 'post-2']);
});

it('keeps a record another process saved while it caches a single file', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);
    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);

    $reading = new PaperManifest($cache, 60, 10, true);
    $reading->records($source);

    $adapter->seed('blog/post-3.md', "---\ntitle: Three\n---\n", 3_000);

    $saving = new PaperManifest($cache, 60, 10, true);
    $saving->put($source, 'post-3', 'blog/post-3.md', ['title' => 'Three'], 'v3');

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);
    $reading->record($source, 'post-1');

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($source)))
        ->toBe(['post-1', 'post-2', 'post-3']);
});

it('drops the manifest rather than merge a save into it without the lock', function (): void {
    $store = new RacingStore;
    $cache = new Repository($store);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $saving = new PaperManifest($cache, 60, 0, false);
    $saving->records($source);

    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
    $store->contended = true;

    $saving->put($source, 'post-2', 'blog/post-2.md', ['title' => 'Two'], 'v2');

    $store->contended = false;
    $adapter->reset();

    $reader = new PaperManifest($cache, 60, 0, false);

    expect(array_keys($reader->records($source)))->toBe(['post-1', 'post-2'])
        ->and($adapter->counts['listing'])->toBe(1);
});

it('serves a rebuild it could not lock without storing it', function (): void {
    $store = new RacingStore;
    $cache = new Repository($store);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 0, false);
    $rebuilding->records($source);

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);
    $store->contended = true;

    $records = $rebuilding->reconcile($source);

    $store->contended = false;

    $reader = new PaperManifest($cache, 60, 0, false);

    expect($records['post-1']['data']['title'])->toBe('Edited')
        ->and($reader->records($source)['post-1']['data']['title'])->toBe('One');
});

it('keeps a record another process deleted while it writes its own', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);
    $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);

    $first = new PaperManifest($cache, 60, 10, false);
    $second = new PaperManifest($cache, 60, 10, false);

    $first->records($source);
    $second->records($source);

    $first->forget($source, 'post-1');
    $second->put($source, 'post-3', 'blog/post-3.md', ['title' => 'Three'], 'v3');

    $reader = new PaperManifest($cache, 60, 10, false);

    expect(array_keys($reader->records($source)))
        ->toBe(['post-2', 'post-3']);
});

it('does not publish a rebuild over a save that could not wait for it', function (): void {
    $cache = new Repository(new ArrayStore);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 0, false);
    $rebuilding->records($source);

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);

    $saving = new PaperManifest($cache, 60, 0, false);

    $adapter->duringNextRead(function () use ($adapter, $saving, $source): void {
        $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
        $saving->put($source, 'post-2', 'blog/post-2.md', ['title' => 'Two'], 'v2');
    });

    $rebuilding->reconcile($source);

    $reader = new PaperManifest($cache, 60, 0, false);

    expect(array_keys($reader->records($source)))->toBe(['post-1', 'post-2']);
});

it('takes back a manifest invalidated between the revision check and the write', function (): void {
    $store = new RacingStore;
    $cache = new Repository($store);
    $adapter = new CountingAdapter;
    $source = new ContentSource($adapter, new MarkdownDriver, 'blog', false);
    $adapter->seed('blog/post-1.md', "---\ntitle: One\n---\n", 1_000);

    $rebuilding = new PaperManifest($cache, 60, 0, false);
    $rebuilding->records($source);

    $adapter->seed('blog/post-1.md', "---\ntitle: Edited\n---\n", 1_500);

    $saving = new PaperManifest($cache, 60, 0, false);

    $store->duringNextWrite(function () use ($adapter, $saving, $source): void {
        $adapter->seed('blog/post-2.md', "---\ntitle: Two\n---\n", 2_000);
        $saving->put($source, 'post-2', 'blog/post-2.md', ['title' => 'Two'], 'v2');
    });

    $rebuilding->reconcile($source);

    $reader = new PaperManifest($cache, 60, 0, false);

    expect(array_keys($reader->records($source)))->toBe(['post-1', 'post-2']);
});
