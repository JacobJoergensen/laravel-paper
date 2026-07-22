<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Article;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DiskDoc;

beforeEach(function (): void {
    PaperQueryBuilder::forgetCache(Article::class);
    PaperQueryBuilder::forgetCache(DiskDoc::class);
    Storage::fake('paper');
});

it('reads, writes, and deletes through the configured disk', function (): void {
    $article = new Article;
    $article->slug = 'first';
    $article->title = 'First';
    $article->content = 'Body';
    $article->save();

    expect(Storage::disk('paper')->exists('articles/first.md'))->toBeTrue();

    $loaded = Article::find('first');

    expect($loaded)->not->toBeNull()
        ->and($loaded->title)->toBe('First');

    $loaded->delete();

    expect(Storage::disk('paper')->exists('articles/first.md'))->toBeFalse();
});

it('lists only files with the driver extension on the disk', function (): void {
    Storage::disk('paper')->put('articles/one.md', "---\ntitle: One\n---\n");
    Storage::disk('paper')->put('articles/two.md', "---\ntitle: Two\n---\n");
    Storage::disk('paper')->put('articles/ignored.txt', 'not markdown');

    $articles = Article::all();

    expect($articles)->toHaveCount(2)
        ->and($articles->pluck('slug')->sort()->values()->toArray())->toBe(['one', 'two']);
});

it('reads nested subdirectories on a disk as multi-segment slugs', function (): void {
    Storage::disk('paper')->put('docs/index.md', "---\ntitle: Index\n---\n");
    Storage::disk('paper')->put('docs/guides/installation.md', "---\ntitle: Installation\n---\n");

    expect(DiskDoc::pluck('slug')->sort()->values()->toArray())->toBe(['guides/installation', 'index'])
        ->and(DiskDoc::find('guides/installation')?->title)->toBe('Installation');
});
