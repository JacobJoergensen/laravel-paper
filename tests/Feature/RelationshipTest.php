<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
    Author::resetPaperState();
});

it('can resolve belongsTo relationship', function (): void {
    $post = Post::find('hello-world');
    $author = $post->author()->getResults();

    expect($author)->not->toBeNull()
        ->and($author->slug)->toBe('john-doe')
        ->and($author->name)->toBe('John Doe');
});

it('returns null for belongsTo when foreign key is null', function (): void {
    $post = Post::find('draft-post');
    $author = $post->author()->getResults();

    expect($author)->toBeNull();
});

it('can resolve hasMany relationship', function (): void {
    $author = Author::find('john-doe');
    $posts = $author->posts()->getResults();

    expect($posts)->toHaveCount(1)
        ->and($posts->first()->slug)->toBe('hello-world');
});

it('queries a belongsTo relation, constrained to the record it points at', function (): void {
    $post = Post::find('hello-world');

    expect($post->author()->query()->first()?->slug)->toBe('john-doe')
        ->and($post->author()->query()->where('name', 'Jane Doe')->first())->toBeNull();
});

it('queries a belongsTo relation whose foreign key is missing as an empty set', function (): void {
    $post = Post::find('draft-post');

    expect($post->author()->query()->get())->toBeEmpty()
        ->and($post->author()->query()->count())->toBe(0);
});

it('queries a hasMany relation, scoped to its parent', function (): void {
    $author = Author::find('john-doe');

    expect($author->posts()->query()->pluck('slug')->all())->toBe(['hello-world'])
        ->and($author->posts()->query()->where('published', false)->get())->toBeEmpty();
});

it('resolves a belongsTo on property access and holds on to the result', function (): void {
    $post = Post::find('hello-world');

    expect($post->author?->slug)->toBe('john-doe')
        ->and($post->author)->toBe($post->author);
});

it('resolves a hasMany on property access', function (): void {
    $author = Author::find('john-doe');

    expect($author->posts->pluck('slug')->all())->toBe(['hello-world']);
});
