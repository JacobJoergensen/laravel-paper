<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Exceptions\StaleRecordException;
use JacobJoergensen\LaravelPaper\Testing\PaperFake;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    PaperFake::fake(Post::class, [
        'a' => ['title' => 'A', 'views' => 1, 'likes' => 5],
        'b' => ['title' => 'B', 'views' => 10],
        'c' => ['title' => 'C', 'views' => 20],
    ]);
});

it('writes an incremented and decremented attribute, along with the extra attributes', function (): void {
    $post = Post::find('a');

    expect($post->increment('views', 5, ['title' => 'Read']))->toBe(1)
        ->and($post->decrement('views'))->toBe(1);

    $stored = Post::find('a');

    expect($stored->views)->toBe(5)
        ->and($stored->title)->toBe('Read');
});

it('changes several attributes in one write with incrementEach and decrementEach', function (): void {
    $post = Post::find('a');

    $post->incrementEach(['views' => 2, 'likes' => 3]);
    $post->decrementEach(['likes' => 1]);

    $stored = Post::find('a');

    expect($stored->views)->toBe(3)
        ->and($stored->likes)->toBe(7);
});

it('fires updating and updated but not saving or saved', function (): void {
    $events = [];

    foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
        Post::{$event}(function () use (&$events, $event): void {
            $events[] = $event;
        });
    }

    Post::find('a')->increment('views');

    expect($events)->toBe(['updating', 'updated']);
});

it('fires no events from the quiet variants', function (): void {
    $events = [];

    foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
        Post::{$event}(function () use (&$events, $event): void {
            $events[] = $event;
        });
    }

    $post = Post::find('a');
    $post->incrementQuietly('views');
    $post->decrementEachQuietly(['likes' => 2]);

    $stored = Post::find('a');

    expect($events)->toBe([])
        ->and($stored->views)->toBe(2)
        ->and($stored->likes)->toBe(3);
});

it('keeps other unsaved changes in memory without writing them', function (): void {
    $post = Post::find('a');
    $post->title = 'Draft';

    $post->increment('views');

    $stored = Post::find('a');

    expect($stored->views)->toBe(2)
        ->and($stored->title)->toBe('A')
        ->and($post->title)->toBe('Draft')
        ->and($post->isDirty('title'))->toBeTrue();
});

it('shows updated listeners the value before the increment as the original', function (): void {
    $seen = null;

    Post::updated(function (Post $post) use (&$seen): void {
        $seen = $post->getOriginal('views');
    });

    $post = Post::find('a');
    $post->increment('views');

    expect($seen)->toBe(1)
        ->and($post->getOriginal('views'))->toBe(2);
});

it('writes nothing when updating cancels the increment', function (): void {
    Post::updating(fn (): bool => false);

    expect(Post::find('a')->increment('views'))->toBeFalse()
        ->and(Post::find('a')->views)->toBe(1);
});

it('increments nothing for a model that was never saved', function (): void {
    $post = new Post(['slug' => 'a']);

    expect($post->increment('views'))->toBeFalse()
        ->and(Post::pluck('views', 'slug')->all())->toBe(['a' => 1, 'b' => 10, 'c' => 20]);
});

it('rejects an amount that is not a number or a column list without names, even when nothing matches', function (Closure $increment): void {
    $increment();
})->throws(InvalidArgumentException::class)->with([
    'non-numeric amount' => fn (): int => Post::where('slug', 'none')->increment('views', 'many'),
    'column list without names' => fn (): int => Post::where('slug', 'none')->incrementEach([5]),
]);

it('increments every matching record and returns how many it wrote', function (): void {
    expect(Post::where('views', '>=', 10)->increment('views', 2))->toBe(2)
        ->and(Post::pluck('views', 'slug')->all())->toBe(['a' => 1, 'b' => 12, 'c' => 22]);
});

it('refuses to increment a record that changed after it was loaded', function (): void {
    $first = Post::find('a');
    $second = Post::find('a');

    $first->increment('views');

    expect(fn (): false|int => $second->increment('views', 10))->toThrow(StaleRecordException::class)
        ->and(Post::find('a')->views)->toBe(2);
});
