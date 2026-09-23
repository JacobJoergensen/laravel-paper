<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\NestedPost;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
    NestedPost::resetPaperState();

    $this->files = [
        __DIR__.'/../content/posts/nested-alpha.md' => "---\nseo:\n  title: Alpha\n  score: 3\n  meta:\n    robots: index\n---\n",
        __DIR__.'/../content/posts/nested-beta.md' => "---\nseo:\n  title: Beta\n  score: 5\n  meta:\n    robots: noindex\n---\n",
    ];

    foreach ($this->files as $path => $contents) {
        File::put($path, $contents);
    }
});

afterEach(function (): void {
    File::delete(array_keys($this->files));
});

it('queries, orders, and aggregates nested frontmatter with dot-notation', function (): void {
    expect(Post::where('seo.title', 'Alpha')->pluck('slug')->all())->toBe(['nested-alpha'])
        ->and(Post::where('seo.meta.robots', 'noindex')->pluck('slug')->all())->toBe(['nested-beta'])
        ->and(Post::where('seo.absent', 'x')->count())->toBe(0)
        ->and(Post::whereNotNull('seo.title')->orderByDesc('seo.title')->pluck('slug')->all())->toBe(['nested-beta', 'nested-alpha'])
        ->and(Post::sum('seo.score'))->toBe(8);
});

it('prefers a literal flat key over a nested lookup', function (): void {
    $path = __DIR__.'/../content/posts/nested-flat.md';
    File::put($path, "---\n'seo.title': Flat\n---\n");

    try {
        expect(Post::where('seo.title', 'Flat')->pluck('slug')->all())->toBe(['nested-flat']);
    } finally {
        File::delete($path);
    }
});

it('gives the same answer whether the nested root is cast or raw', function (): void {
    expect(NestedPost::where('seo.title', 'Alpha')->pluck('slug')->all())->toBe(['nested-alpha'])
        ->and(NestedPost::where('seo.meta.robots', 'noindex')->pluck('slug')->all())->toBe(['nested-beta'])
        ->and(NestedPost::whereNotNull('seo.title')->orderByDesc('seo.title')->pluck('slug')->all())->toBe(['nested-beta', 'nested-alpha']);
});
