<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TimestampedPost;

beforeEach(function (): void {
    TimestampedPost::resetPaperState();
});

afterEach(function (): void {
    foreach (glob(__DIR__.'/../content/posts/__ts_test__*') ?: [] as $file) {
        @unlink($file);
    }
});

it('exposes the file modification time as updated_at when timestamps are enabled', function (): void {
    $path = base_path('tests/content/posts/hello-world.md');

    $post = TimestampedPost::find('hello-world');

    expect($post->updated_at)->toBeInstanceOf(Carbon::class)
        ->and($post->updated_at->getTimestamp())->toBe(filemtime($path));
});

it('does not persist the derived updated_at into the file on save', function (): void {
    $post = new TimestampedPost;
    $post->slug = '__ts_test__';
    $post->title = 'First';
    $post->save();

    $reloaded = TimestampedPost::find('__ts_test__');
    $reloaded->title = 'Second';
    $reloaded->save();

    $raw = file_get_contents(base_path('tests/content/posts/__ts_test__.md'));

    expect($reloaded->updated_at)->not->toBeNull()
        ->and($raw)->not->toContain('updated_at');
});
