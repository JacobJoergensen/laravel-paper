<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidDriverException;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\PostCollection;

beforeEach(function (): void {
    Post::resetPaperState();
});

it('returns the collection declared with the #[CollectedBy] attribute', function (): void {
    $posts = Post::all();

    expect($posts)->toBeInstanceOf(PostCollection::class)
        ->and($posts->published())->toHaveCount(2);
});

it('excludes attributes declared with the #[Hidden] attribute from the array form', function (): void {
    $post = Post::find('hello-world');

    expect($post->toArray())
        ->toHaveKey('title')
        ->not->toHaveKey('order');
})->skip(! class_exists(Hidden::class), 'The #[Hidden] attribute requires Laravel 13.');

it('accepts only attributes listed in #[Fillable] when filling from an array', function (): void {
    $post = new Post;
    $post->fill([
        'slug' => 'allowed',
        'title' => 'Allowed',
        'secret' => 'Dropped',
    ]);

    expect($post->slug)->toBe('allowed')
        ->and($post->title)->toBe('Allowed')
        ->and($post->secret)->toBeNull();
})->skip(! class_exists(Fillable::class), 'The #[Fillable] attribute requires Laravel 13.');

it('resolves the driver on first use rather than when the model is constructed', function (): void {
    $model = new #[Driver('missing')] class extends Model
    {
        use Paper;
    };

    expect(fn (): bool => $model->usesTimestamps())->toThrow(InvalidDriverException::class);
});
