<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Testing\PaperFake;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    PaperFake::fake(Author::class, [
        'amy' => ['name' => 'Amy'],
        'bob' => ['name' => 'Bob'],
        'cal' => ['name' => 'Cal'],
    ]);

    PaperFake::fake(Post::class, [
        'bob-1' => ['title' => 'Bob One', 'author_slug' => 'bob', 'published' => true],
        'cal-1' => ['title' => 'Cal One', 'author_slug' => 'cal', 'published' => true],
        'cal-2' => ['title' => 'Cal Two', 'author_slug' => 'cal', 'published' => false],
        'cal-3' => ['title' => 'Cal Three', 'author_slug' => 'cal', 'published' => false],
    ]);
});

it('counts a relation onto each record without loading it', function (): void {
    $authors = Author::withCount('posts')->get();

    expect($authors->pluck('posts_count', 'slug')->all())->toBe(['amy' => 0, 'bob' => 1, 'cal' => 3])
        ->and($authors->contains(fn (Author $author): bool => $author->relationLoaded('posts')))->toBeFalse();
});

it('counts constrained relations under their own names', function (): void {
    $published = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('published', true);
    $drafts = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('published', false);

    $cal = Author::withCount([
        'posts as published_count' => $published,
        'posts AS drafts_count' => $drafts,
    ])->find('cal');

    expect($cal?->published_count)->toBe(1)
        ->and($cal?->drafts_count)->toBe(2);
});

it('filters and orders by a counted relation', function (): void {
    $slugs = Author::withCount('posts')
        ->where('posts_count', '>', 0)
        ->orderByDesc('posts_count')
        ->pluck('slug')
        ->all();

    expect($slugs)->toBe(['cal', 'bob']);
});

it('counts every relation it is given onto records already in memory', function (Closure $load): void {
    $cal = $load();

    expect($cal->posts_count)->toBe(3)
        ->and($cal->total)->toBe(3);
})->with([
    'collection' => fn (): ?Author => Author::all()->loadCount('posts', 'posts as total')->last(),
    'model' => fn (): Author => Author::findOrFail('cal')->loadCount('posts', 'posts as total'),
]);

it('includes a count in the array form unless it is hidden', function (): void {
    $cal = Author::withCount('posts')->find('cal');

    expect($cal?->toArray())->toHaveKey('posts_count', 3)
        ->and($cal?->makeHidden('posts_count')->toArray())->not->toHaveKey('posts_count');
});

it('drops counts when the record is refreshed', function (): void {
    $cal = Author::withCount('posts')->where('slug', 'cal')->firstOrFail();

    $cal->refresh();

    expect($cal->posts_count)->toBeNull();
});

it('never writes a count into the file when the record is saved', function (): void {
    $cal = Author::withCount('posts')->where('slug', 'cal')->firstOrFail();
    $cal->name = 'Renamed';
    $cal->save();

    expect(Author::findOrFail('cal')->getAttributes())->not->toHaveKey('posts_count');
});
