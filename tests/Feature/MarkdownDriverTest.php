<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
});

it('can find a post by slug', function (): void {
    $post = Post::find('hello-world');

    expect($post)->not->toBeNull()
        ->and($post->slug)->toBe('hello-world')
        ->and($post->title)->toBe('Hello World')
        ->and($post->published)->toBeTrue();
});

it('returns null for non-existent slug', function (): void {
    $post = Post::find('does-not-exist');

    expect($post)->toBeNull();
});

it('can get all posts', function (): void {
    $posts = Post::all();

    expect($posts)->toHaveCount(3);
});

it('can filter posts with where clause', function (): void {
    $posts = Post::where('published', true)->get();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('slug')->toArray())->each->not->toBe('draft-post');
});

it('can filter posts with two-argument string where', function (): void {
    $post = Post::where('title', 'Hello World')->first();

    expect($post)->not->toBeNull()
        ->and($post->slug)->toBe('hello-world');
});

it('can order posts', function (): void {
    $posts = Post::query()->orderBy('order', 'desc')->get();

    expect($posts->first()->slug)->toBe('draft-post')
        ->and($posts->last()->slug)->toBe('hello-world');
});

it('can limit results', function (): void {
    $posts = Post::query()->limit(2)->get();

    expect($posts)->toHaveCount(2);
});

it('uses slug as primary key', function (): void {
    $post = Post::find('hello-world');

    expect($post->getKey())->toBe('hello-world')
        ->and($post->getKeyName())->toBe('slug');
});

it('can reload model with fresh', function (): void {
    $post = Post::find('hello-world');
    $post->title = 'Modified Title';

    $fresh = $post->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->title)->toBe('Hello World')
        ->and($post->title)->toBe('Modified Title');
});

it('can reload model in place with refresh', function (): void {
    $post = Post::find('hello-world');
    $post->title = 'Modified Title';

    $returned = $post->refresh();

    expect($returned)->toBe($post)
        ->and($post->title)->toBe('Hello World');
});

it('can use local scopes', function (): void {
    $posts = Post::query()->published()->get();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('published')->unique()->toArray())->toBe([true]);
});
