<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\PostCollection;

it('attaches the matching parent for each belongsTo relation', function (): void {
    $posts = Post::with('author')->get();
    $helloWorld = $posts->firstWhere('slug', 'hello-world');

    expect($helloWorld->author)->not->toBeNull()
        ->and($helloWorld->author->slug)->toBe('john-doe');
});

it('attaches null for belongsTo when the foreign key is missing', function (): void {
    $posts = Post::with('author')->get();
    $draft = $posts->firstWhere('slug', 'draft-post');

    expect($draft->author)->toBeNull();
});

it('attaches children grouped by parent and an empty collection when there are none', function (): void {
    $authors = Author::with('posts')->get();
    $john = $authors->firstWhere('slug', 'john-doe');
    $jane = $authors->firstWhere('slug', 'jane-doe');

    expect($john->posts)->toHaveCount(1)
        ->and($john->posts->first()->slug)->toBe('hello-world')
        ->and($jane->posts)->toHaveCount(0);
});

it('eager loads relations across every model returned by findMany', function (): void {
    $posts = Post::with('author')->findMany(['hello-world', 'draft-post']);

    expect($posts)->toHaveCount(2)
        ->and($posts->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue();
});

it('eager loads relations for the current page of paginators', function (): void {
    $paginated = Post::with('author')->paginate(2)->getCollection();
    $simple = Post::with('author')->simplePaginate(2)->getCollection();

    expect($paginated)->toHaveCount(2)
        ->and($paginated->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue()
        ->and($simple)->toHaveCount(2)
        ->and($simple->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue();
});

it('eager loads relations when taking only the first result', function (): void {
    $post = Post::with('author')->first();

    expect($post->relationLoaded('author'))->toBeTrue();
});

it('attaches relations for a numeric slug, which PHP stores as an integer array key', function (): void {
    $author = __DIR__.'/../content/authors/123.json';
    $post = __DIR__.'/../content/posts/numeric-author.md';

    File::put($author, '{"name": "Numeric"}');
    File::put($post, "---\ntitle: Numeric Author\nauthor_slug: 123\n---\n");

    try {
        $loaded = Post::with('author')->get()->firstWhere('slug', 'numeric-author');
        $authors = Author::with('posts')->get()->firstWhere('slug', '123');

        expect($loaded->author)->not->toBeNull()
            ->and($loaded->author->slug)->toBe('123')
            ->and($authors->posts->pluck('slug')->all())->toBe(['numeric-author']);
    } finally {
        File::delete($author, $post);
    }
});

it('throws when with references a missing method', function (): void {
    Post::with('nonexistent')->get();
})->throws(BadMethodCallException::class, 'Post::nonexistent does not exist.');

it('throws when with references a method returning the wrong type', function (): void {
    Post::with('getKeyName')->get();
})->throws(BadMethodCallException::class, 'Post::getKeyName must return');

it('eager loads children into the collection the related model declares', function (): void {
    $authors = Author::with('posts')->get();

    expect($authors->firstWhere('slug', 'john-doe')->posts)->toBeInstanceOf(PostCollection::class)
        ->and($authors->firstWhere('slug', 'jane-doe')->posts)->toBeInstanceOf(PostCollection::class);
});

it('applies a constraint given with the relation to a hasMany eager load', function (): void {
    $authors = Author::with(['posts' => fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('published', false)])->get();

    expect($authors->firstWhere('slug', 'john-doe')->posts)->toHaveCount(0);
});

it('applies a constraint given with the relation to a belongsTo eager load', function (): void {
    $posts = Post::with(['author' => fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('name', 'Jane Doe')])->get();

    expect($posts->firstWhere('slug', 'hello-world')->author)->toBeNull()
        ->and($posts->firstWhere('slug', 'hello-world')->relationLoaded('author'))->toBeTrue();
});

it('lazy eager loads a relation onto a model that is already in memory', function (): void {
    $post = Post::find('hello-world');

    expect($post->relationLoaded('author'))->toBeFalse();

    $post->load('author');

    expect($post->relationLoaded('author'))->toBeTrue()
        ->and($post->getRelation('author')->slug)->toBe('john-doe');
});

it('lazy eager loads a relation only when the model does not have it yet', function (): void {
    $post = Post::find('hello-world');
    $post->loadMissing('author');

    expect($post->getRelation('author')->slug)->toBe('john-doe');

    $post->setRelation('author', null);
    $post->loadMissing('author');

    expect($post->getRelation('author'))->toBeNull();
});

it('lazy eager loads a relation across a collection', function (): void {
    $authors = Author::all()->load('posts');

    expect($authors->firstWhere('slug', 'john-doe')->posts->pluck('slug')->all())->toBe(['hello-world']);
});

it('lazy eager loads across a collection declared with #[CollectedBy]', function (): void {
    $posts = Post::all()->load('author');

    expect($posts)->toBeInstanceOf(PostCollection::class)
        ->and($posts->firstWhere('slug', 'hello-world')->getRelation('author')->slug)->toBe('john-doe');
});

it('lazy eager loads across a collection without touching a model that already has the relation', function (): void {
    $authors = Author::all();
    $john = $authors->firstWhere('slug', 'john-doe');
    $john->setRelation('posts', new PostCollection);

    $authors->loadMissing('posts');

    expect($john->getRelation('posts'))->toBeEmpty()
        ->and($authors->firstWhere('slug', 'jane-doe')->relationLoaded('posts'))->toBeTrue();
});

it('eager loads the relations given to fresh', function (): void {
    $post = Post::find('hello-world');

    $fresh = $post->fresh(['author']);

    expect($fresh->relationLoaded('author'))->toBeTrue()
        ->and($fresh->getRelation('author')->slug)->toBe('john-doe');
});

it('lazy eager loads every relation name it is given', function (): void {
    expect(fn (): mixed => Post::all()->load('author', 'nonexistent'))
        ->toThrow(BadMethodCallException::class, 'Post::nonexistent does not exist.');
});
