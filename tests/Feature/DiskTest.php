<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Article;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DiskDoc;

beforeEach(function (): void {
    Article::resetPaperState();
    DiskDoc::resetPaperState();
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

it('reports a disk relative file path, not an absolute one', function (): void {
    $article = new Article;
    $article->slug = 'first';

    expect($article->getFilePath())->toBe('articles/first.md');
});

it('reads nested subdirectories on a disk as multi-segment slugs', function (): void {
    Storage::disk('paper')->put('docs/index.md', "---\ntitle: Index\n---\n");
    Storage::disk('paper')->put('docs/guides/installation.md', "---\ntitle: Installation\n---\n");

    expect(DiskDoc::pluck('slug')->sort()->values()->toArray())->toBe(['guides/installation', 'index'])
        ->and(DiskDoc::find('guides/installation')?->title)->toBe('Installation');
});
