<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\ExtendedPost;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
});

it('returns each relation keyed by name with its descriptor', function (): void {
    $relations = Post::find('hello-world')->paperRelations();

    expect(array_keys($relations))->toBe(['author'])
        ->and($relations['author'])->toBeInstanceOf(BelongsToPaper::class)
        ->and($relations['author']->relatedClass)->toBe(Author::class)
        ->and($relations['author']->foreignKey)->toBe('author_slug');
});

it('enumerates inherited relations without invoking anything else', function (): void {
    $relations = new ExtendedPost()->paperRelations();

    expect(array_keys($relations))->toEqualCanonicalizing(['author', 'pages']);
});

it('binds each relation to the model it came from', function (): void {
    $first = Post::find('hello-world');
    $second = Post::find('second-post');

    expect($first->paperRelations()['author']->parent)->toBe($first)
        ->and($second->paperRelations()['author']->parent)->toBe($second);
});

it('ignores a relation method that declares no return type', function (): void {
    $post = new ExtendedPost;

    expect($post->writer())->toBeInstanceOf(BelongsToPaper::class)
        ->and($post->paperRelations())->not->toHaveKey('writer');
});

it('ignores a static relation method', function (): void {
    expect(new ExtendedPost()->paperRelations())->not->toHaveKey('defaultAuthor');
});

it('ignores a relation built on another model', function (): void {
    $post = new ExtendedPost;

    expect($post->authorPosts()->parent)->not->toBe($post)
        ->and($post->paperRelations())->not->toHaveKey('authorPosts');
});
