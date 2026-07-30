<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JacobJoergensen\LaravelPaper\Exceptions\MissingTimestampsException;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DatedPost;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TimestampedPost;

beforeEach(function (): void {
    TimestampedPost::resetPaperState();
    DatedPost::resetPaperState();
    Post::resetPaperState();
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

it('keeps the frontmatter value when the timestamp column names a real field', function (): void {
    $post = DatedPost::find('hello-world');

    expect($post->date->toDateString())->toBe('2024-01-15');
});

it('does not strip a frontmatter timestamp column from the file on save', function (): void {
    $dir = base_path('tests/content/posts');
    file_put_contents("$dir/__ts_test__dated.md", "---\ntitle: Dated\ndate: 2024-03-01\n---\n\nx\n");

    $post = DatedPost::find('__ts_test__dated');
    $post->title = 'Renamed';
    $post->save();

    DatedPost::resetPaperState();

    expect(DatedPost::find('__ts_test__dated')->date->toDateString())->toBe('2024-03-01');
});

it('orders an authored timestamp column by its frontmatter value when paginating', function (): void {
    $dir = base_path('tests/content/posts');

    // The reverse of the frontmatter date order, so sorting by mtime cannot pass by accident.
    $mtimes = [
        'draft-post.markdown' => 1_700_000_100,
        'second-post.md' => 1_700_000_200,
        'hello-world.md' => 1_700_000_300,
    ];

    $original = [];

    foreach ($mtimes as $name => $mtime) {
        $path = $dir.'/'.$name;
        $original[$path] = filemtime($path);
        touch($path, $mtime);
    }

    clearstatcache();

    try {
        $paginated = DatedPost::query()->orderBy('date')->paginate(perPage: 10)->pluck('slug')->all();
        $unpaginated = DatedPost::query()->orderBy('date')->get()->pluck('slug')->all();

        expect($paginated)->toBe(['hello-world', 'second-post', 'draft-post'])
            ->and($unpaginated)->toBe($paginated);
    } finally {
        foreach ($original as $path => $mtime) {
            touch($path, $mtime);
        }
    }
});

it('leaves updated_at unset when timestamps are not enabled', function (): void {
    $post = Post::find('hello-world');

    expect($post->updated_at)->toBeNull();
});

it('orders by updated_at by default with latest and oldest', function (): void {
    $dir = base_path('tests/content/posts');
    file_put_contents("$dir/__ts_test__old.md", "---\ntitle: Old\n---\n\nx\n");
    file_put_contents("$dir/__ts_test__new.md", "---\ntitle: New\n---\n\nx\n");
    touch("$dir/__ts_test__old.md", 1_000_000_000);
    touch("$dir/__ts_test__new.md", 2_000_000_000);

    $latest = TimestampedPost::latest()->get()->pluck('slug');
    $oldest = TimestampedPost::oldest()->get()->pluck('slug');

    expect($latest->first())->toBe('__ts_test__new')
        ->and($latest->last())->toBe('__ts_test__old')
        ->and($oldest->first())->toBe('__ts_test__old')
        ->and($oldest->last())->toBe('__ts_test__new');
});

it('throws when latest or oldest is called without timestamps enabled', function (): void {
    expect(fn () => Post::latest())->toThrow(MissingTimestampsException::class)
        ->and(fn () => Post::oldest())->toThrow(MissingTimestampsException::class);
});

it('orders by an explicit column when timestamps are not enabled', function (): void {
    $order = Post::latest('order')->get()->pluck('order')->all();

    expect($order)->toBe([3, 2, 1]);
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
