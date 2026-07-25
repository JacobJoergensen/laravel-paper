<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawModel;

beforeEach(function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;

    $adapter->seed('blog/a.md', "---\ntitle: Hello World\n---\n", 1_000);
    $adapter->seed('blog/b.md', "---\ntitle: Second Post\n---\n", 2_000);
    $adapter->seed('blog/c.md', "---\nsummary: no title here\n---\n", 3_000);

    $this->build = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawModel::class);
});

it('excludes matches and missing fields with whereNotLike', function (): void {
    expect(($this->build)()->whereNotLike('title', '%Hello%')->get()->pluck('slug')->all())->toBe(['b']);
});

it('filters with whereRegexp and whereNotRegexp', function (): void {
    expect(($this->build)()->whereRegexp('title', '/^Second/')->get()->pluck('slug')->all())->toBe(['b'])
        ->and(($this->build)()->whereRegexp('title', '/o/i')->get()->pluck('slug')->all())->toBe(['a', 'b'])
        ->and(($this->build)()->whereNotRegexp('title', '/^Second/')->get()->pluck('slug')->all())->toBe(['a']);
});

it('ors each like and regexp variant onto an existing filter', function (): void {
    expect(($this->build)()->where('slug', 'c')->orWhereLike('title', '%Hello%')->get()->pluck('slug')->all())->toBe(['a', 'c'])
        ->and(($this->build)()->where('slug', 'a')->orWhereNotLike('title', '%Hello%')->get()->pluck('slug')->all())->toBe(['a', 'b'])
        ->and(($this->build)()->where('slug', 'c')->orWhereRegexp('title', '/^Second/')->get()->pluck('slug')->all())->toBe(['b', 'c'])
        ->and(($this->build)()->where('slug', 'b')->orWhereNotRegexp('title', '/^Second/')->get()->pluck('slug')->all())->toBe(['a', 'b']);
});

it('throws on an invalid regex pattern at build time', function (): void {
    expect(fn () => ($this->build)()->whereRegexp('title', 'no-delimiters'))->toThrow(InvalidArgumentException::class);
});
