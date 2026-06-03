<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

it('attaches the matching parent for each belongsTo relation', function (): void {
    $posts = Post::with('author')->get();
    $helloWorld = $posts->firstWhere('slug', 'hello-world');

    expect($helloWorld->author)->not->toBeNull()
        ->and($helloWorld->author->slug)->toBe('john-doe');
});

it('attaches null for belongsTo when the foreign key is missing', function (): void {
    $posts = Post::with('author')->get();
    $draft = $posts->firstWhere('slug', 'draft-post');

    expect($draft->author)->toBeNull();
});

it('attaches children grouped by parent and an empty collection when there are none', function (): void {
    $authors = Author::with('posts')->get();
    $john = $authors->firstWhere('slug', 'john-doe');
    $jane = $authors->firstWhere('slug', 'jane-doe');

    expect($john->posts)->toHaveCount(1)
        ->and($john->posts->first()->slug)->toBe('hello-world')
        ->and($jane->posts)->toHaveCount(0);
});

it('eager loads relations across every model returned by findMany', function (): void {
    $posts = Post::with('author')->findMany(['hello-world', 'draft-post']);

    expect($posts->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue()
        ->and($posts->firstWhere('slug', 'hello-world')->author->slug)->toBe('john-doe')
        ->and($posts->firstWhere('slug', 'draft-post')->author)->toBeNull();
});

it('eager loads relations for the current page of paginators', function (): void {
    $paginated = Post::with('author')->paginate(2)->getCollection();
    $simple = Post::with('author')->simplePaginate(2)->getCollection();

    expect($paginated)->toHaveCount(2)
        ->and($paginated->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue()
        ->and($simple)->toHaveCount(2)
        ->and($simple->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue();
});

it('throws when with references a missing method', function (): void {
    Post::with('nonexistent')->get();
})->throws(BadMethodCallException::class);

it('throws when with references a method returning the wrong type', function (): void {
    Post::with('getKeyName')->get();
})->throws(BadMethodCallException::class);
