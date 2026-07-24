<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
    Author::resetPaperState();
});

it('filters parents by whether a hasMany relation exists', function (): void {
    expect(Author::has('posts')->get()->pluck('slug')->all())->toBe(['john-doe'])
        ->and(Author::doesntHave('posts')->get()->pluck('slug')->all())->toBe(['jane-doe']);
});

it('filters a belongsTo relation with has', function (): void {
    expect(Post::has('author')->get()->pluck('slug')->all())->toBe(['hello-world']);
});

it('constrains the relation with whereHas and whereRelation', function (): void {
    expect(Author::whereHas('posts', fn (PaperQueryBuilder $query) => $query->where('published', true))->get()->pluck('slug')->all())->toBe(['john-doe'])
        ->and(Post::whereRelation('author', 'name', 'John Doe')->get()->pluck('slug')->all())->toBe(['hello-world']);
});

it('honours a count constraint', function (): void {
    expect(Author::has('posts', '>=', 2)->get())->toHaveCount(0)
        ->and(Author::has('posts', '>=', 1)->get()->pluck('slug')->all())->toBe(['john-doe']);
});

it('rejects a dot-nested relation name', function (): void {
    expect(fn () => Author::has('posts.author'))->toThrow(InvalidArgumentException::class);
});
