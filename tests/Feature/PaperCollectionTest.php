<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidCollectionException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedCollectionMethodException;
use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
});

it('refuses a collection method that would run a database query', function (string $method, array $arguments): void {
    $posts = Post::all();

    expect(fn (): mixed => $posts->{$method}(...$arguments))
        ->toThrow(UnsupportedCollectionMethodException::class);
})->with([
    ['loadCount', ['author']],
    ['loadExists', ['author']],
    ['loadMax', ['author', 'name']],
    ['loadMorph', ['author', []]],
    ['loadMorphCount', ['author', []]],
    ['toQuery', []],
]);

it('re-queries the models and leaves the ones it was called on untouched', function (): void {
    $posts = Post::all();
    $post = $posts->firstWhere('slug', 'hello-world');
    $post->title = 'Edited in memory';

    $fresh = $posts->fresh();

    expect($fresh->firstWhere('slug', 'hello-world')->title)->toBe('Hello World')
        ->and($post->title)->toBe('Edited in memory');
});

it('drops a record that is no longer in storage when refreshing', function (): void {
    $gone = new Post;
    $gone->slug = 'gone';
    $posts = Post::all()->push($gone);

    expect($posts->fresh()->pluck('slug')->all())->not->toContain('gone');
});

it('passes every relation named as a separate argument to fresh, not just the first', function (): void {
    $posts = Post::all();

    expect(fn (): Collection => $posts->fresh('author', 'missing'))
        ->toThrow(BadMethodCallException::class, 'missing');
});

it('autoloads a relation across the whole collection when the app opts in', function (): void {
    Model::automaticallyEagerLoadRelationships();

    try {
        $posts = Post::all()->sortBy('slug')->values();
        $posts->first()->author;

        expect($posts->last()->relationLoaded('author'))->toBeTrue();
    } finally {
        Model::automaticallyEagerLoadRelationships(false);
    }
})->skip(
    ! method_exists(Model::class, 'isAutomaticallyEagerLoadingRelationships'),
    'Automatic relationship loading requires a newer Laravel 12.',
);

it('rejects a collection named by #[CollectedBy] that does not extend PaperCollection', function (): void {
    $model = new #[CollectedBy(Collection::class)] class extends PaperModel {};

    expect(fn (): Collection => $model->newCollection())
        ->toThrow(InvalidCollectionException::class, 'must extend');
});
