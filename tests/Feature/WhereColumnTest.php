<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\BrokenModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawModel;

it('compares two fields, with a default operator and an or variant', function (): void {
    $adapter = new CountingAdapter;
    $adapter->seed('blog/a.md', "---\nmin: 1\nmax: 5\n---\n", 1_000);
    $adapter->seed('blog/b.md', "---\nmin: 5\nmax: 5\n---\n", 2_000);
    $adapter->seed('blog/c.md', "---\nmin: 8\nmax: 3\n---\n", 3_000);

    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $build = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawModel::class);

    expect($build()->whereColumn('min', '<', 'max')->get()->pluck('slug')->all())->toBe(['a'])
        ->and($build()->whereColumn('min', 'max')->get()->pluck('slug')->all())->toBe(['b'])
        ->and($build()->whereColumn('min', '>', 'max')->orWhereColumn('min', 'max')->get()->pluck('slug')->all())->toBe(['b', 'c']);
});

it('rejects a mismatched cast status but leaves a matching one alone', function (): void {
    expect(fn () => BrokenModel::whereColumn('published_at', '>', 'legacy_date'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Post::whereColumn('tags', '=', 'views'))
        ->not->toThrow(InvalidArgumentException::class);
});
