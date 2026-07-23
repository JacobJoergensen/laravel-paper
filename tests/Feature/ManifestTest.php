<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TimestampedPost;

beforeEach(function (): void {
    $this->manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10);
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

it('re-reads only the file whose mtime is newer than the cached entry', function (): void {
    ($this->build)()->get();

    $this->adapter->reset();
    $this->adapter->seed('blog/post-3.md', "---\nstatus: draft\n---\nedited", 9_999);

    $models = ($this->build)()->get();

    expect($this->adapter->counts['listing'])->toBe(1)
        ->and($this->adapter->counts['read'])->toBe(1)
        ->and($models->firstWhere('slug', 'post-3')->status)->toBe('draft');
});

it('re-reads a file whose mtime moved backwards, as a restore from backup would', function (): void {
    ($this->build)()->get();

    $this->adapter->reset();
    $this->adapter->seed('blog/post-3.md', "---\nstatus: draft\n---\nrestored", 500);

    $models = ($this->build)()->get();

    expect($this->adapter->counts['read'])->toBe(1)
        ->and($models->firstWhere('slug', 'post-3')->status)->toBe('draft');
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
