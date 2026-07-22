<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
});

it('resolves a model from its route key', function (): void {
    $post = new Post;

    expect($post->resolveRouteBinding('hello-world')?->slug)->toBe('hello-world');
});

it('resolves a model from a custom route key field', function (): void {
    $post = new Post;

    expect($post->resolveRouteBinding('Hello World', 'title')?->slug)->toBe('hello-world');
});

it('returns null when no model matches the route key', function (): void {
    $post = new Post;

    expect($post->resolveRouteBinding('does-not-exist'))->toBeNull();
});

it('returns null when the route key field is absent from frontmatter', function (): void {
    $post = new Post;

    expect($post->resolveRouteBinding('hello-world', 'nonexistent'))->toBeNull();
});

it('resolves a child model scoped to its parent', function (): void {
    $author = Author::find('john-doe');

    expect($author->resolveChildRouteBinding('post', 'hello-world', null)?->slug)->toBe('hello-world');
});

it('returns null when the child belongs to another parent', function (): void {
    $author = Author::find('jane-doe');

    expect($author->resolveChildRouteBinding('post', 'hello-world', null))->toBeNull();
});

it('resolves a child model from a custom binding field', function (): void {
    $author = Author::find('john-doe');

    expect($author->resolveChildRouteBinding('post', 'Hello World', 'title')?->slug)->toBe('hello-world');
});

it('returns null for a child when the parent has no key', function (): void {
    $author = new Author;

    expect($author->resolveChildRouteBinding('post', 'hello-world', null))->toBeNull();
});

it('throws when the child relation does not exist on the parent', function (): void {
    $post = new Post;

    $post->resolveChildRouteBinding('author', 'john-doe', null);
})->throws(BadMethodCallException::class);

it('substitutes scoped bindings through the router', function (): void {
    Route::middleware(SubstituteBindings::class)
        ->get('/authors/{author}/posts/{post}', fn (Author $author, Post $post): string => $post->slug)
        ->scopeBindings();

    $this->get('/authors/john-doe/posts/hello-world')
        ->assertOk()
        ->assertSee('hello-world');

    $this->get('/authors/jane-doe/posts/hello-world')->assertNotFound();
});
