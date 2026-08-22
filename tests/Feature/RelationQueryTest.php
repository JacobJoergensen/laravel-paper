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
        ->and(Author::whereHas('posts', fn (PaperQueryBuilder $query) => $query->where('published', false))->get())->toBeEmpty()
        ->and(Post::whereRelation('author', 'name', 'John Doe')->get()->pluck('slug')->all())->toBe(['hello-world'])
        ->and(Post::whereRelation('author', 'name', 'Jane Doe')->get())->toBeEmpty();
});

it('keeps parents whose related records all fail the constraint with whereDoesntHave', function (): void {
    expect(Author::whereDoesntHave('posts', fn (PaperQueryBuilder $query) => $query->where('published', true))->get()->pluck('slug')->all())->toBe(['jane-doe'])
        ->and(Author::whereDoesntHave('posts', fn (PaperQueryBuilder $query) => $query->where('published', false))->get()->pluck('slug')->all())->toBe(['jane-doe', 'john-doe']);
});

it('honours a count constraint', function (): void {
    expect(Author::has('posts', '>=', 2)->get())->toHaveCount(0)
        ->and(Author::has('posts', '>=', 1)->get()->pluck('slug')->all())->toBe(['john-doe']);
});

it('ors each relation filter onto an existing condition', function (): void {
    $published = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('published', true);

    expect(Author::where('slug', 'jane-doe')->orHas('posts')->get()->pluck('slug')->all())->toBe(['jane-doe', 'john-doe'])
        ->and(Author::where('slug', 'john-doe')->orDoesntHave('posts')->get()->pluck('slug')->all())->toBe(['jane-doe', 'john-doe'])
        ->and(Author::where('slug', 'jane-doe')->orWhereHas('posts', $published)->get()->pluck('slug')->all())->toBe(['jane-doe', 'john-doe'])
        ->and(Author::where('slug', 'john-doe')->orWhereDoesntHave('posts', $published)->get()->pluck('slug')->all())->toBe(['jane-doe', 'john-doe'])
        ->and(Post::where('slug', 'second-post')->orWhereRelation('author', 'name', 'John Doe')->get()->pluck('slug')->all())->toBe(['hello-world', 'second-post']);
});

it('rejects a dot-nested relation name', function (): void {
    expect(fn () => Author::has('posts.author'))->toThrow(InvalidArgumentException::class);
});

it('narrows the related query with a constraint passed to has and doesntHave', function (): void {
    $other = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('name', 'Jane Doe');

    expect(Post::has('author', '>=', 1, $other)->get())->toBeEmpty()
        ->and(Post::doesntHave('author', $other)->get()->pluck('slug')->all())->toBe(['draft-post', 'hello-world', 'second-post']);
});
