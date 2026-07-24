<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingModel;

beforeEach(function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;

    foreach (range(1, 5) as $i) {
        $published = $i <= 2 ? 'true' : 'false';
        $adapter->seed("blog/post-{$i}.md", "---\npublished: {$published}\nrank: {$i}\n---\n", 1_000 + $i);
    }

    $this->build = fn (): PaperQueryBuilder => new PaperQueryBuilder(
        $adapter, new MarkdownDriver, $manifest, 'blog', CountingModel::class,
    );
});

it('builds only the matching models when the filter column has no cast', function (): void {
    CountingModel::$hydrations = 0;

    $published = ($this->build)()->where('published', true)->get();

    $onUncast = CountingModel::$hydrations;
    CountingModel::$hydrations = 0;

    $ranked = ($this->build)()->where('rank', '>', 0)->get();

    expect($published->pluck('slug')->all())->toBe(['post-1', 'post-2'])
        ->and($ranked)->toHaveCount(5)
        ->and($onUncast)->toBeLessThan(CountingModel::$hydrations);
});

it('counts a pushdown filter from the raw records, building none of the matches', function (): void {
    CountingModel::$hydrations = 0;
    $count = ($this->build)()->where('published', true)->count();
    $onCount = CountingModel::$hydrations;

    CountingModel::$hydrations = 0;
    ($this->build)()->where('published', true)->get();
    $onGet = CountingModel::$hydrations;

    expect($count)->toBe(2)
        ->and($onCount)->toBeLessThan($onGet);
});

it('plucks a safe column from raw records without building models', function (): void {
    CountingModel::$hydrations = 0;
    $safe = ($this->build)()->pluck('published');
    $onSafe = CountingModel::$hydrations;

    CountingModel::$hydrations = 0;
    ($this->build)()->pluck('rank');
    $onCast = CountingModel::$hydrations;

    expect($safe)->toHaveCount(5)
        ->and($onSafe)->toBeLessThan($onCast);
});

it('pushes the filter down for aggregates', function (): void {
    CountingModel::$hydrations = 0;
    $sum = ($this->build)()->where('published', true)->sum('rank');
    $onUncast = CountingModel::$hydrations;

    CountingModel::$hydrations = 0;
    ($this->build)()->where('rank', '>', 0)->sum('rank');
    $onCast = CountingModel::$hydrations;

    expect($sum)->toBe(3)
        ->and($onUncast)->toBeLessThan($onCast);
});
