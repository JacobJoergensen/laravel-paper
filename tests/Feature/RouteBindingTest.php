<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedRouteBindingException;
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

it('throws for scoped child route bindings', function (): void {
    $post = new Post;

    $post->resolveChildRouteBinding('author', 'someone', null);
})->throws(UnsupportedRouteBindingException::class);
