<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DatedPost;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
    DatedPost::resetPaperState();

    $this->timezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Copenhagen');
});

afterEach(function (): void {
    date_default_timezone_set($this->timezone);
});

it('filters by year, month, day, and date', function (): void {
    expect(Post::whereYear('date', 2024)->count())->toBe(3)
        ->and(Post::whereYear('date', '>', 2024)->count())->toBe(0)
        ->and(Post::whereMonth('date', 1)->count())->toBe(3)
        ->and(Post::whereDay('date', '>', 15)->pluck('slug')->all())->toBe(['draft-post', 'second-post'])
        ->and(Post::whereDate('date', '2024-01-20')->pluck('slug')->all())->toBe(['second-post'])
        ->and(Post::whereDay('date', 15)->orWhereDate('date', '2024-01-25')->pluck('slug')->all())
        ->toBe(['draft-post', 'hello-world']);
});

it('accepts a Carbon value', function (): void {
    expect(Post::whereDate('date', Carbon::parse('2024-01-15'))->pluck('slug')->all())->toBe(['hello-world'])
        ->and(Post::whereYear('date', Carbon::parse('2025-01-01'))->count())->toBe(0);
});

it('gives the same answer whether the date column is cast or raw', function (): void {
    expect(DatedPost::whereYear('date', 2024)->count())->toBe(3)
        ->and(DatedPost::whereMonth('date', 1)->count())->toBe(3)
        ->and(DatedPost::whereDay('date', 15)->pluck('slug')->all())->toBe(['hello-world'])
        ->and(DatedPost::whereDate('date', '2024-01-20')->pluck('slug')->all())->toBe(['second-post']);
});

it('excludes a record whose date cannot be parsed', function (): void {
    $path = __DIR__.'/../content/posts/broken-date.md';
    File::put($path, "---\ndate: banana\n---\n");

    try {
        expect(Post::count())->toBe(4)
            ->and(Post::whereYear('date', 2024)->count())->toBe(3);
    } finally {
        File::delete($path);
    }
});
