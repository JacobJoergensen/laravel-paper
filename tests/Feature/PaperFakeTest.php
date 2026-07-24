<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\Testing\PaperFake;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DiskDoc;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
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

it('round-trips nested array frontmatter through serialize and parse', function (): void {
    PaperFake::fake(Post::class, [
        'a' => ['title' => 'Alpha', 'meta' => ['seo' => ['keywords' => ['x', 'y']], 'flags' => [1, 2]]],
    ]);

    expect(Post::find('a')->meta)->toBe(['seo' => ['keywords' => ['x', 'y']], 'flags' => [1, 2]]);
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

// Two mirror guards: each asserts the other's fake is gone before making its own. Author is
// faked nowhere else, so the second to run only sees a clean slate if teardown cleared the first.
it('does not leak a fake into the next test (guard one)', function (): void {
    expect(Author::find('guard-two'))->toBeNull();

    PaperFake::fake(Author::class, ['guard-one' => ['name' => 'One']]);

    expect(Author::find('guard-one'))->not->toBeNull();
});

it('does not leak a fake into the next test (guard two)', function (): void {
    expect(Author::find('guard-one'))->toBeNull();

    PaperFake::fake(Author::class, ['guard-two' => ['name' => 'Two']]);

    expect(Author::find('guard-two'))->not->toBeNull();
});
