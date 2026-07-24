<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawNestedModel;

beforeEach(function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/a.md', "---\nstatus: active\norder: 1\n---\n", 1_000);
    $adapter->seed('blog/b.md', "---\nstatus: active\norder: 2\n---\n", 2_000);
    $adapter->seed('blog/c.md', "---\nstatus: draft\norder: 3\n---\n", 3_000);

    $this->adapter = $adapter;
    $this->build = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawNestedModel::class);
});

it('applies an array of conditions as AND, in triple and assoc form', function (): void {
    expect(($this->build)()->where([['status', '=', 'active'], ['order', '>', 1]])->get()->pluck('slug')->all())->toBe(['b'])
        ->and(($this->build)()->where([['status', 'active']])->get()->pluck('slug')->all())->toBe(['a', 'b'])
        ->and(($this->build)()->where(['status' => 'active'])->get()->pluck('slug')->all())->toBe(['a', 'b']);
});

it('groups the array so a following orWhere ors the whole set', function (): void {
    $slugs = ($this->build)()
        ->where([['status', '=', 'active'], ['order', '=', 1]])
        ->orWhere('status', 'draft')
        ->get()
        ->pluck('slug')
        ->all();

    expect($slugs)->toBe(['a', 'c']);
});

it('throws on a malformed condition instead of widening the query', function (): void {
    expect(fn () => ($this->build)()->where(['status']))->toThrow(InvalidArgumentException::class);
});

it('ignores an empty condition array, keeping the count fast path', function (): void {
    ($this->build)()->where([])->count();

    expect($this->adapter->counts['read'])->toBe(0);
});
