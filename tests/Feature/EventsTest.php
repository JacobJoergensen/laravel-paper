<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\PostObserver;

beforeEach(function (): void {
    Post::resetPaperState();
});

afterEach(function (): void {
    $dir = __DIR__.'/../content/posts';

    foreach (glob($dir.'/__save_test__*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob($dir.'/.paper-*') ?: [] as $file) {
        @unlink($file);
    }
});

it('fires lifecycle events to observers registered with #[ObservedBy]', function (): void {
    PostObserver::$events = [];

    $post = new Post;
    $post->slug = '__save_test__observed';
    $post->title = 'Observed';
    $post->save();
    $post->delete();

    expect(PostObserver::$events)->toBe(['created', 'deleted']);
});

it('fires a retrieved event for each model a query hands back', function (): void {
    PostObserver::$events = [];

    Post::find('hello-world');
    Post::all();

    expect(PostObserver::$events)->toBe(['retrieved', 'retrieved', 'retrieved', 'retrieved']);
});

it('does not fire retrieved when only counting or checking existence', function (): void {
    PostObserver::$events = [];

    Post::where('published', true)->count();
    Post::where('published', true)->exists();

    expect(PostObserver::$events)->toBe([]);
});

it('does not fire retrieved for the related models a has query only counts', function (): void {
    Author::resetPaperState();
    PostObserver::$events = [];

    Author::has('posts')->get();

    expect(PostObserver::$events)->toBe([]);
});

it('fires retrieved only for the models on the requested page', function (): void {
    PostObserver::$events = [];

    Post::paginate(2);

    expect(PostObserver::$events)->toHaveCount(2);
});

it('does not fire events when saved with saveQuietly', function (): void {
    PostObserver::$events = [];

    $post = new Post;
    $post->slug = '__save_test__quiet';
    $post->title = 'Quiet';
    $post->saveQuietly();

    expect(PostObserver::$events)->toBe([])
        ->and(Post::find('__save_test__quiet'))->not->toBeNull();
});

it('does not fire events when deleted with deleteQuietly', function (): void {
    $post = new Post;
    $post->slug = '__save_test__quietdelete';
    $post->title = 'Quiet Delete';
    $post->save();

    PostObserver::$events = [];
    $post->deleteQuietly();

    expect(PostObserver::$events)->toBe([])
        ->and(Post::find('__save_test__quietdelete'))->toBeNull();
});
