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

it('orders by updated_at by default with latest and oldest', function (): void {
    $dir = base_path('tests/content/posts');
    file_put_contents("$dir/__ts_test__old.md", "---\ntitle: Old\n---\n\nx\n");
    file_put_contents("$dir/__ts_test__new.md", "---\ntitle: New\n---\n\nx\n");
    touch("$dir/__ts_test__old.md", 1_000_000_000);
    touch("$dir/__ts_test__new.md", 2_000_000_000);

    $latest = TimestampedPost::latest()->get()->pluck('slug');
    $oldest = TimestampedPost::oldest()->get()->pluck('slug');

    expect($latest->search('__ts_test__new'))->toBeLessThan($latest->search('__ts_test__old'))
        ->and($oldest->search('__ts_test__old'))->toBeLessThan($oldest->search('__ts_test__new'));
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

it('orders by updated_at identically whether or not the fast path runs', function (): void {
    $dir = base_path('tests/content/posts');

    $mtimes = [
        'second-post.md' => 1_700_000_300,
        'draft-post.markdown' => 1_700_000_200,
        'hello-world.md' => 1_700_000_200,
    ];

    $original = [];

    foreach ($mtimes as $name => $mtime) {
        $path = $dir.'/'.$name;
        $original[$path] = filemtime($path);
        touch($path, $mtime);
    }

    clearstatcache();

    try {
        $fastPath = TimestampedPost::query()
            ->orderByDesc('updated_at')
            ->paginate(perPage: 10)
            ->pluck('slug')
            ->all();

        $fullParse = TimestampedPost::query()
            ->whereNotNull('slug')
            ->orderByDesc('updated_at')
            ->paginate(perPage: 10)
            ->pluck('slug')
            ->all();

        expect($fastPath)->toBe(['second-post', 'draft-post', 'hello-world'])
            ->and($fullParse)->toBe($fastPath);
    } finally {
        foreach ($original as $path => $mtime) {
            touch($path, $mtime);
        }
    }
});
