<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingStore;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
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
