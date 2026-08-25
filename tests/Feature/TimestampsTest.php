<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DatedPost;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TimestampedPost;

beforeEach(function (): void {
    TimestampedPost::resetPaperState();
    DatedPost::resetPaperState();
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

it('orders by the model CREATED_AT column when latest is given no column', function (): void {
    expect(DatedPost::latest()->pluck('slug')->all())->toBe(['draft-post', 'second-post', 'hello-world']);
});

it('orders an authored timestamp column by its frontmatter value when paginating', function (): void {
    $paginated = DatedPost::query()->orderBy('date')->paginate(perPage: 10)->pluck('slug')->all();
    $unpaginated = DatedPost::query()->orderBy('date')->get()->pluck('slug')->all();

    expect($paginated)->toBe(['hello-world', 'second-post', 'draft-post'])
        ->and($unpaginated)->toBe($paginated);
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

it('orders by the derived updated_at using the file mtime', function (): void {
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
        $ordered = TimestampedPost::query()
            ->orderByDesc('updated_at')
            ->paginate(perPage: 10)
            ->pluck('slug')
            ->all();

        expect($ordered)->toBe(['second-post', 'draft-post', 'hello-world']);
    } finally {
        foreach ($original as $path => $mtime) {
            touch($path, $mtime);
        }
    }
});
