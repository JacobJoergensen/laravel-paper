<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Testing\FakeAdapter;
use JacobJoergensen\LaravelPaper\Testing\PaperFake;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DiskDoc;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\PostStatus;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TimestampedPost;

it('queries faked content without touching disk', function (): void {
    PaperFake::fake(Post::class, [
        'a' => ['title' => 'Alpha', 'published' => true],
        'b' => ['title' => 'Beta', 'published' => false],
    ]);

    expect(Post::count())->toBe(2)
        ->and(Post::where('published', true)->pluck('slug')->all())->toBe(['a']);
});

it('runs the real cast pipeline on faked content, resolved through find', function (): void {
    PaperFake::fake(Post::class, [
        'a' => ['title' => 'Alpha', 'tags' => ['x', 'y'], 'views' => '3'],
    ]);

    $post = Post::find('a');

    expect($post)->not->toBeNull()
        ->and($post->tags)->toBe(['x', 'y'])
        ->and($post->views)->toBe(3);
});

it('supports save and delete against the fake', function (): void {
    PaperFake::fake(Post::class, ['a' => ['title' => 'Alpha']]);

    new Post(['slug' => 'b', 'title' => 'Beta'])->save();
    expect(Post::find('b')?->title)->toBe('Beta');

    Post::find('a')?->delete();
    expect(Post::find('a'))->toBeNull();
});

it('derives reproducible updated_at from a fixed mtime base', function (): void {
    PaperFake::fake(TimestampedPost::class, [
        'a' => ['title' => 'Alpha'],
        'b' => ['title' => 'Beta'],
    ]);

    expect(TimestampedPost::find('a')->updated_at->getTimestamp())->toBe(PaperFake::BASE_MTIME)
        ->and(TimestampedPost::find('b')->updated_at->getTimestamp())->toBe(PaperFake::BASE_MTIME + 1);
});

it('dates a record saved through the fake after everything it was seeded with', function (): void {
    PaperFake::fake(TimestampedPost::class, [
        'a' => ['title' => 'Alpha'],
        'b' => ['title' => 'Beta'],
    ]);

    new TimestampedPost(['slug' => 'c', 'title' => 'Gamma'])->save();

    expect(TimestampedPost::orderByDesc('updated_at')->first()?->slug)->toBe('c');
});

it('seeds an attribute through the model cast, like save does', function (): void {
    $model = new class extends Post
    {
        protected function casts(): array
        {
            return ['status' => PostStatus::class];
        }
    };

    PaperFake::fake($model::class, ['a' => ['status' => PostStatus::Draft]]);

    expect($model::find('a')?->status)->toBe(PostStatus::Draft);
});

it('rejects a seeded slug that save would reject', function (string $slug): void {
    PaperFake::fake(Post::class, [$slug => ['title' => 'Nope']]);
})->throws(InvalidSlugException::class)->with([
    'outside the content path' => '../escape',
    'in a subdirectory of a flat model' => 'guide/intro',
]);

it('rejects a seeded slug Windows cannot store as a filename on Windows', function (): void {
    PaperFake::fake(Post::class, ['a:b' => ['title' => 'Nope']]);
})->throws(InvalidSlugException::class)->skip(PHP_OS_FAMILY !== 'Windows', 'Only Windows rejects these characters.');

it('leaves hidden files out, like real storage', function (): void {
    $adapter = PaperFake::fake(Post::class, ['a' => ['title' => 'Alpha']]);
    $adapter->seed(PaperQueryBuilder::contentPathFor(Post::class).'/.hidden.md', "---\ntitle: Hidden\n---\n", 1);

    expect(Post::pluck('slug')->all())->toBe(['a']);
});

it('keeps the fake when the model state is reset mid-test', function (): void {
    PaperFake::fake(Post::class, ['a' => ['title' => 'Alpha']]);

    Post::resetPaperState();

    expect(Post::find('a')?->title)->toBe('Alpha');
});

it('gives each fake its own manifest key, even after an earlier one is freed', function (): void {
    $keys = [];

    for ($i = 0; $i < 10; $i++) {
        $keys[] = new FakeAdapter()->cacheKey('posts');
        gc_collect_cycles();
    }

    expect(array_unique($keys))->toHaveCount(10);
});

it('restores the real adapter after reset', function (): void {
    PaperFake::fake(Post::class, ['ghost' => ['title' => 'Ghost']]);
    expect(Post::find('ghost')?->title)->toBe('Ghost');

    PaperFake::reset();

    expect(Post::find('ghost'))->toBeNull()
        ->and(Post::find('hello-world'))->not->toBeNull();
});

it('does not serve a stale manifest when the same model is re-faked', function (): void {
    PaperFake::fake(Post::class, ['a' => ['title' => 'A'], 'b' => ['title' => 'B'], 'c' => ['title' => 'C']]);
    expect(Post::all())->toHaveCount(3);

    PaperFake::fake(Post::class, ['a' => ['title' => 'A']]);
    expect(Post::all())->toHaveCount(1);
});

it('fakes a disk-backed model without flipping the content path', function (): void {
    Storage::fake('paper');

    PaperFake::fake(DiskDoc::class, ['guide/intro' => ['title' => 'Intro']]);

    expect(DiskDoc::find('guide/intro')?->title)->toBe('Intro');
});

it('does not leak a fake into the next test', function (string $mine, string $other): void {
    expect(Author::find($other))->toBeNull();

    PaperFake::fake(Author::class, [$mine => ['name' => 'Guard']]);

    expect(Author::find($mine))->not->toBeNull();
})->with([
    ['guard-one', 'guard-two'],
    ['guard-two', 'guard-one'],
]);
