<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Testing\PaperFake;
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

it('eager loads relations across every chunk of a lazy result', function (): void {
    $posts = Post::with('author')->lazy(2)->all();

    expect($posts)->toHaveCount(3)
        ->and(collect($posts)->every(fn (Post $post): bool => $post->relationLoaded('author')))->toBeTrue();
});

it('eager loads relations for the models handed to chunk', function (): void {
    $loaded = [];

    Post::with('author')->chunk(2, function (Collection $posts) use (&$loaded): void {
        $loaded[] = $posts->every(fn (Post $post): bool => $post->relationLoaded('author'));
    });

    expect($loaded)->toBe([true, true]);
});

it('eager loads relations for the models handed to each', function (): void {
    $loaded = [];

    Post::with('author')->each(function (Post $post) use (&$loaded): void {
        $loaded[] = $post->relationLoaded('author');
    });

    expect($loaded)->toBe([true, true, true]);
});

it('eager loads relations for the record returned by sole', function (): void {
    $post = Post::with('author')->where('slug', 'hello-world')->sole();

    expect($post->relationLoaded('author'))->toBeTrue()
        ->and($post->getRelation('author')->slug)->toBe('john-doe');
});

it('attaches relations for a numeric slug, which PHP stores as an integer array key', function (): void {
    $author = base_path('tests/content/authors/123.json');
    $post = base_path('tests/content/posts/numeric-author.md');

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
    $drafts = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('published', false);
    $authors = Author::with(['posts' => $drafts])->get();

    expect($authors->firstWhere('slug', 'john-doe')->posts)->toHaveCount(0);
});

it('applies a constraint given with the relation to a belongsTo eager load', function (): void {
    $jane = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('name', 'Jane Doe');
    $posts = Post::with(['author' => $jane])->get();

    expect($posts->firstWhere('slug', 'hello-world')->author)->toBeNull()
        ->and($posts->firstWhere('slug', 'hello-world')->relationLoaded('author'))->toBeTrue();
});

it('refuses an eager load constraint that limits the results, since it would span every parent', function (): void {
    $limited = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->limit(1);

    Author::with(['posts' => $limited])->get();
})->throws(InvalidArgumentException::class, 'sets a limit or offset');

it('applies an offset in an eager load constraint for a single model', function (): void {
    PaperFake::fake(Author::class, ['jo' => ['name' => 'Jo']]);
    PaperFake::fake(Post::class, [
        'first' => ['title' => 'First', 'author_slug' => 'jo'],
        'second' => ['title' => 'Second', 'author_slug' => 'jo'],
    ]);

    $author = Author::find('jo');
    $skipped = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->orderBy('slug')->offset(1);

    $author->load(['posts' => $skipped]);

    expect($author->posts->pluck('slug')->all())->toBe(['second']);
});

it('applies a constraint passed as the second argument, like Eloquent', function (): void {
    $drafts = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('published', false);
    $authors = Author::with('posts', $drafts)->get();

    expect($authors->firstWhere('slug', 'john-doe')->posts)->toHaveCount(0);
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

it('reloads the relations it already had when refreshing', function (): void {
    $path = base_path('tests/content/posts/refreshed.md');
    File::put($path, "---\ntitle: Refreshed\nauthor_slug: john-doe\n---\n");

    try {
        $post = Post::find('refreshed');

        expect($post->author->slug)->toBe('john-doe');

        $rewritten = Post::find('refreshed');
        $rewritten->author_slug = 'jane-doe';
        $rewritten->save();

        $post->refresh();

        expect($post->getRelation('author')->slug)->toBe('jane-doe');
    } finally {
        File::delete($path);
    }
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

it('eager loads a nested relation through dot notation, stopping at a missing parent', function (): void {
    $posts = Post::with('author.posts')->get()->keyBy('slug');
    $author = $posts['hello-world']->author;

    expect($author->relationLoaded('posts'))->toBeTrue()
        ->and($author->posts->pluck('slug')->all())->toBe(['hello-world'])
        ->and($posts['draft-post']->author)->toBeNull();
});

it('applies a constraint on a dotted relation to its last segment', function (): void {
    $unpublished = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('published', false);

    $post = Post::with(['author.posts' => $unpublished])->find('hello-world');

    expect($post->author?->slug)->toBe('john-doe')
        ->and($post->author->posts)->toHaveCount(0);
});

it('keeps the constraint on a relation that is also named as the start of a nested path', function (): void {
    $jane = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('name', 'Jane Doe');

    $post = Post::with(['author' => $jane], 'author.posts')->find('hello-world');

    expect($post->author)->toBeNull();
});

it('loads only the missing nested relation onto a parent that is already loaded', function (): void {
    $post = Post::with('author')->find('hello-world');
    $author = $post->author;

    $post->loadMissing('author.posts');

    expect($post->author)->toBe($author)
        ->and($author->relationLoaded('posts'))->toBeTrue();
});
