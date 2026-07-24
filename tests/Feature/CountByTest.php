<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawNestedModel;

beforeEach(function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/a.md', "---\ntags: [laravel, php]\ncategory: guide\n---\n", 1_000);
    $adapter->seed('blog/b.md', "---\ntags: [laravel]\ncategory: guide\n---\n", 2_000);
    $adapter->seed('blog/c.md', "---\ncategory: news\n---\n", 3_000);
    $adapter->seed('blog/d.md', "---\nseo:\n  keywords: [a, b]\n---\n", 4_000);

    $this->build = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawNestedModel::class);
});

it('flattens an array column and skips records missing the field', function (): void {
    expect(($this->build)()->countBy('tags')->all())->toBe(['laravel' => 2, 'php' => 1]);
});

it('tallies a scalar column in first-seen order', function (): void {
    expect(($this->build)()->countBy('category')->all())->toBe(['guide' => 2, 'news' => 1]);
});

it('skips a nested array value instead of fataling on an illegal array key', function (): void {
    expect(($this->build)()->countBy('seo')->all())->toBe([]);
});

it('returns an empty collection when nothing matches', function (): void {
    $counts = ($this->build)()->where('category', 'missing')->countBy('tags');

    expect($counts)->toBeInstanceOf(Collection::class)->toBeEmpty();
});
