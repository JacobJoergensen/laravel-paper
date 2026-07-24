<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CastKeyModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\NestedModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawNestedModel;

beforeEach(function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/alpha.md', "---\nseo:\n  title: Alpha\n  meta:\n    robots: index\n---\n", 1_000);
    $adapter->seed('blog/beta.md', "---\nseo:\n  title: Beta\n  meta:\n    robots: noindex\n---\n", 2_000);

    $this->raw = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawNestedModel::class);
    $this->cast = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', NestedModel::class);
});

it('queries into nested frontmatter with dot-notation', function (): void {
    expect(($this->raw)()->where('seo.title', 'Alpha')->get()->pluck('slug')->all())->toBe(['alpha'])
        ->and(($this->raw)()->where('seo.meta.robots', 'noindex')->get()->pluck('slug')->all())->toBe(['beta'])
        ->and(($this->raw)()->where('seo.title', 'missing')->get())->toHaveCount(0)
        ->and(($this->raw)()->where('seo.absent', 'x')->get())->toHaveCount(0)
        ->and(($this->raw)()->orderBy('seo.title')->get()->pluck('slug')->all())->toBe(['alpha', 'beta']);
});

it('prefers a literal flat key over a nested lookup', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('flat/doc.md', "---\n'seo.title': Flat\n---\n", 1_000);

    $query = new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'flat', RawNestedModel::class);

    expect($query->where('seo.title', 'Flat')->get()->pluck('slug')->all())->toBe(['doc']);
});

it('does not push a filter down onto a cast flat key', function (): void {
    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('cast/doc.md', "---\n'seo.count': '10'\n---\n", 1_000);

    $query = new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'cast', CastKeyModel::class);

    expect($query->where('seo.count', '===', 10)->get()->pluck('slug')->all())->toBe(['doc']);
});

it('gives the same answer whether the nested root is cast or raw', function (): void {
    $cases = [
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->where('seo.title', 'Beta'),
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->where('seo.meta.robots', 'index'),
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->orderBy('seo.title'),
    ];

    foreach ($cases as $case) {
        $raw = $case(($this->raw)())->get()->pluck('slug')->all();
        $cast = $case(($this->cast)())->get()->pluck('slug')->all();

        expect($cast)->toBe($raw);
    }
});
