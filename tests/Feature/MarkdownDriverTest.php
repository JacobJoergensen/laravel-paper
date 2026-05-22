<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Draft;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\PostCollection;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\PostObserver;

beforeEach(function (): void {
    Post::resetPaperState();
});

afterEach(function (): void {
    $dir = __DIR__.'/../content/posts';

    foreach (glob($dir.'/__save_test__*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob($dir.'/.paper-*') ?: [] as $file) {
        @unlink($file);
    }
});

it('can find a post by slug', function (): void {
    $post = Post::find('hello-world');

    expect($post)->not->toBeNull()
        ->and($post->slug)->toBe('hello-world')
        ->and($post->title)->toBe('Hello World')
        ->and($post->published)->toBeTrue();
});

it('returns null for non-existent slug', function (): void {
    $post = Post::find('does-not-exist');

    expect($post)->toBeNull();
});

it('discovers files across every driver extension', function (): void {
    $post = Post::find('draft-post');

    expect($post)->not->toBeNull()
        ->and($post->slug)->toBe('draft-post');
});

it('excludes null fields from comparison operators', function (): void {
    expect(Post::where('author_slug', 0)->count())->toBe(0)
        ->and(Post::where('author_slug', '!=', 0)->count())->toBe(1)
        ->and(Post::where('author_slug', '<', 'mmm')->count())->toBe(1);
});

it('can get all posts', function (): void {
    $posts = Post::all();

    expect($posts)->toHaveCount(3);
});

it('can filter posts with where clause', function (): void {
    $posts = Post::where('published', true)->get();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('slug')->toArray())->each->not->toBe('draft-post');
});

it('can filter posts with two-argument string where', function (): void {
    $post = Post::where('title', 'Hello World')->first();

    expect($post)->not->toBeNull()
        ->and($post->slug)->toBe('hello-world');
});

it('can order posts', function (): void {
    $posts = Post::query()->orderBy('order', 'desc')->get();

    expect($posts->first()->slug)->toBe('draft-post')
        ->and($posts->last()->slug)->toBe('hello-world');
});

it('can limit results', function (): void {
    $posts = Post::query()->limit(2)->get();

    expect($posts)->toHaveCount(2);
});

it('uses slug as primary key', function (): void {
    $post = Post::find('hello-world');

    expect($post->getKey())->toBe('hello-world')
        ->and($post->getKeyName())->toBe('slug');
});

it('can reload model with fresh', function (): void {
    $post = Post::find('hello-world');
    $post->title = 'Modified Title';

    $fresh = $post->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->title)->toBe('Hello World')
        ->and($post->title)->toBe('Modified Title');
});

it('can reload model in place with refresh', function (): void {
    $post = Post::find('hello-world');
    $post->title = 'Modified Title';

    $returned = $post->refresh();

    expect($returned)->toBe($post)
        ->and($post->title)->toBe('Hello World');
});

it('can use local scopes', function (): void {
    $posts = Post::query()->published()->get();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('published')->unique()->toArray())->toBe([true]);
});

it('resolves protected scopes declared with the #[Scope] attribute', function (): void {
    $posts = Post::query()->withOrder(2)->get();

    expect($posts)->toHaveCount(1)
        ->and($posts->first()->slug)->toBe('second-post');
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

it('fires lifecycle events to observers registered with #[ObservedBy]', function (): void {
    PostObserver::$events = [];

    $post = new Post;
    $post->slug = '__save_test__observed';
    $post->title = 'Observed';
    $post->save();
    $post->delete();

    expect(PostObserver::$events)->toBe(['created', 'deleted']);
});

it('does not fire events when saved with saveQuietly', function (): void {
    PostObserver::$events = [];

    $post = new Post;
    $post->slug = '__save_test__quiet';
    $post->title = 'Quiet';
    $post->saveQuietly();

    expect(PostObserver::$events)->toBe([])
        ->and(Post::find('__save_test__quiet'))->not->toBeNull();
});

it('reads a hand-authored YAML list into an array-cast attribute', function (): void {
    $post = Post::find('hello-world');

    expect($post->tags)->toBe(['laravel', 'markdown']);
});

it('writes array-cast attributes as native structures, not JSON strings', function (): void {
    $post = new Post;
    $post->slug = '__save_test__cast';
    $post->title = 'Cast';
    $post->tags = ['php', 'laravel'];
    $post->save();

    $raw = file_get_contents(__DIR__.'/../content/posts/__save_test__cast.md');

    expect($raw)->toContain('- php')->not->toContain('["')
        ->and(Post::find('__save_test__cast')->tags)->toBe(['php', 'laravel']);
});

it('creates a record with create()', function (): void {
    $post = Post::create([
        'slug' => '__save_test__created',
        'title' => 'Created',
    ]);

    expect($post->exists)->toBeTrue()
        ->and(Post::find('__save_test__created')->title)->toBe('Created');
});

it('throws when creating without a slug', function (): void {
    Post::create(['title' => 'No Slug']);
})->throws(InvalidSlugException::class);

it('returns the existing record or creates one with firstOrCreate', function (): void {
    $existing = Post::firstOrCreate(['slug' => 'hello-world'], ['title' => 'Ignored']);

    expect($existing->title)->toBe('Hello World');

    $created = Post::firstOrCreate(['slug' => '__save_test__foc'], ['title' => 'Made']);

    expect($created->title)->toBe('Made')
        ->and(Post::find('__save_test__foc'))->not->toBeNull();
});

it('updates the existing record or creates one with updateOrCreate', function (): void {
    $created = Post::updateOrCreate(['slug' => '__save_test__uoc'], ['title' => 'First']);

    expect($created->title)->toBe('First');

    Post::updateOrCreate(['slug' => '__save_test__uoc'], ['title' => 'Second']);

    expect(Post::find('__save_test__uoc')->title)->toBe('Second');
});

it('can resolve belongsTo relationship', function (): void {
    $post = Post::find('hello-world');
    $author = $post->author();

    expect($author)->not->toBeNull()
        ->and($author->slug)->toBe('john-doe')
        ->and($author->name)->toBe('John Doe');
});

it('returns null for belongsTo when foreign key is null', function (): void {
    $post = Post::find('draft-post');
    $author = $post->author();

    expect($author)->toBeNull();
});

it('can resolve hasMany relationship', function (): void {
    $author = Author::find('john-doe');
    $posts = $author->posts();

    expect($posts)->toHaveCount(1)
        ->and($posts->first()->slug)->toBe('hello-world');
});

it('counts all posts without parsing files', function (): void {
    expect(Post::count())->toBe(3);
});

it('counts only posts matching where clause', function (): void {
    $count = Post::where('published', true)->count();

    expect($count)->toBe(2);
});

it('returns true when posts exist', function (): void {
    expect(Post::exists())->toBeTrue();
});

it('returns true for doesntExist when no posts match', function (): void {
    $result = Post::where('slug', 'does-not-exist')->doesntExist();

    expect($result)->toBeTrue();
});

it('returns sole record when exactly one matches', function (): void {
    $post = Post::where('slug', 'hello-world')->sole();

    expect($post->slug)->toBe('hello-world');
});

it('throws ModelNotFoundException when sole finds no records', function (): void {
    Post::where('slug', 'does-not-exist')->sole();
})->throws(ModelNotFoundException::class);

it('throws MultipleRecordsFoundException when sole finds multiple records', function (): void {
    Post::where('published', true)->sole();
})->throws(MultipleRecordsFoundException::class);

it('paginates using the Paginator resolvers so it works without a request', function (): void {
    Paginator::currentPageResolver(fn () => 2);
    Paginator::currentPathResolver(fn () => 'http://example.test/posts');

    $page = Post::paginate(perPage: 1);

    expect($page->currentPage())->toBe(2)
        ->and($page->path())->toBe('http://example.test/posts')
        ->and($page->total())->toBe(3)
        ->and($page)->toHaveCount(1);
});

it('writes save atomically and leaves no temp files behind', function (): void {
    $post = new Post;
    $post->slug = '__save_test__';
    $post->title = 'Atomic Write';
    $post->published = true;

    expect($post->save())->toBeTrue();

    $written = Post::find('__save_test__');

    expect($written)->not->toBeNull()
        ->and($written->title)->toBe('Atomic Write')
        ->and(glob(__DIR__.'/../content/posts/.paper-*') ?: [])->toBeEmpty();
});

it('matches with whereLike and respects case sensitivity', function (): void {
    expect(Post::whereLike('title', '%post%')->count())->toBe(2)
        ->and(Post::whereLike('title', '%post%', caseSensitive: true)->count())->toBe(0)
        ->and(Post::whereLike('title', '%Post%', caseSensitive: true)->count())->toBe(2)
        ->and(Post::whereLike('title', 'Hello')->count())->toBe(0)
        ->and(Post::whereLike('title', 'Hello World')->count())->toBe(1);
});

it('rejects unsafe slugs when finding', function (string $slug): void {
    Post::find($slug);
})->throws(InvalidSlugException::class)->with([
    'parent traversal' => '../../etc/passwd',
    'backslash' => '..\\..\\secret',
    'null byte' => "foo\0bar",
]);

it('clears dirty state and records changes after saving', function (): void {
    $post = new Post;
    $post->slug = '__save_test__';
    $post->title = 'First';
    $post->save();

    expect($post->wasRecentlyCreated)->toBeTrue()
        ->and($post->isDirty())->toBeFalse();

    $post->title = 'Second';
    $post->save();

    expect($post->isDirty())->toBeFalse()
        ->and($post->wasChanged('title'))->toBeTrue()
        ->and($post->getOriginal('title'))->toBe('Second');
});

it('marks the model as not existing after a successful delete', function (): void {
    $post = new Post;
    $post->slug = '__save_test__';
    $post->title = 'Temp';
    $post->save();

    expect($post->exists)->toBeTrue();

    $post->delete();

    expect($post->exists)->toBeFalse();
});

it('rejects path traversal when saving', function (): void {
    $post = new Post;
    $post->slug = '../../routes/web';
    $post->title = 'Nope';

    $post->save();
})->throws(InvalidSlugException::class);

it('rejects path traversal when deleting', function (): void {
    $post = new Post;
    $post->slug = '../../config/app';
    $post->exists = true;

    $post->delete();
})->throws(InvalidSlugException::class);

it('runs the callback when truthy and the default when falsy', function (): void {
    $whenTrue = Post::query()->when(true, fn ($q) => $q->where('published', true))->count();
    $whenFalse = Post::query()->when(false, fn ($q) => $q->where('published', true))->count();
    $withDefault = Post::query()->when(false, fn ($q) => $q->where('published', true), fn ($q) => $q->where('published', false))->count();

    expect($whenTrue)->toBe(2)
        ->and($whenFalse)->toBe(3)
        ->and($withDefault)->toBe(1);
});

it('matches across columns with whereAny and whereAll', function (): void {
    expect(Post::whereAny(['title', 'content'], 'like', '%post%')->count())->toBe(3)
        ->and(Post::whereAll(['title', 'content'], 'like', '%post%')->count())->toBe(2);
});

it('filters with whereIn and whereNotIn', function (): void {
    expect(Post::whereIn('order', [1, 2])->count())->toBe(2)
        ->and(Post::whereNotIn('order', [1])->count())->toBe(2);
});

it('filters with whereBetween and whereNotBetween', function (): void {
    expect(Post::whereBetween('order', [1, 2])->count())->toBe(2)
        ->and(Post::whereNotBetween('order', [1, 2])->count())->toBe(1);
});

it('filters with whereNull and whereNotNull', function (): void {
    expect(Post::whereNull('author_slug')->count())->toBe(2)
        ->and(Post::whereNotNull('author_slug')->count())->toBe(1);
});

it('filters with whereContains on an array field', function (): void {
    expect(Post::whereContains('tags', 'laravel')->count())->toBe(1)
        ->and(Post::whereContains('tags', 'php')->count())->toBe(0);
});

it('returns every record when ordered randomly', function (): void {
    $random = Post::inRandomOrder()->get();
    $all = Post::all();

    expect($random->pluck('slug')->sort()->values()->toArray())
        ->toBe($all->pluck('slug')->sort()->values()->toArray())
        ->and(Post::inRandomOrder()->limit(2)->get())->toHaveCount(2);
});

it('returns a single column value from the first match', function (): void {
    expect(Post::where('slug', 'hello-world')->value('title'))->toBe('Hello World')
        ->and(Post::where('slug', 'does-not-exist')->value('title'))->toBeNull();
});

it('returns the first record matching a where condition', function (): void {
    $post = Post::firstWhere('slug', 'hello-world');

    expect($post)->not->toBeNull()
        ->and($post->slug)->toBe('hello-world')
        ->and(Post::firstWhere('slug', 'does-not-exist'))->toBeNull();

});

it('reports more pages without counting every record', function (): void {
    $first = Post::query()->simplePaginate(perPage: 2, page: 1);
    $second = Post::query()->simplePaginate(perPage: 2, page: 2);

    expect($first)->toHaveCount(2)
        ->and($first->hasMorePages())->toBeTrue()
        ->and($second)->toHaveCount(1)
        ->and($second->hasMorePages())->toBeFalse();
});

it('saves a model whose slug is "0"', function (): void {
    $dir = __DIR__.'/../content/drafts';
    File::deleteDirectory($dir);

    Draft::resetPaperState();

    $draft = new Draft;
    $draft->slug = '0';
    $draft->title = 'Zero';

    expect($draft->save())->toBeTrue()
        ->and(Draft::find('0'))->not->toBeNull();

    File::deleteDirectory($dir);
});

it('creates the content directory when it does not exist', function (): void {
    $dir = __DIR__.'/../content/drafts';
    File::deleteDirectory($dir);

    Draft::resetPaperState();

    $draft = new Draft;
    $draft->slug = 'first-draft';
    $draft->title = 'First Draft';

    expect($draft->save())->toBeTrue()
        ->and(is_dir($dir))->toBeTrue()
        ->and(Draft::find('first-draft'))->not->toBeNull();

    File::deleteDirectory($dir);
});

it('throws when querying a model whose content directory is missing', function (): void {
    File::deleteDirectory(__DIR__.'/../content/drafts');

    Draft::resetPaperState();

    Draft::all();
})->throws(ContentPathNotFoundException::class);

it('overwrites the existing .markdown file instead of creating a duplicate', function (): void {
    $dir = __DIR__.'/../content/posts';
    file_put_contents($dir.'/__save_test__.markdown', "---\ntitle: Original\n---\n\nBody\n");

    Post::resetPaperState();

    $post = Post::find('__save_test__');
    $post->title = 'Updated';

    expect($post->save())->toBeTrue()
        ->and(file_exists($dir.'/__save_test__.markdown'))->toBeTrue()
        ->and(file_exists($dir.'/__save_test__.md'))->toBeFalse()
        ->and(glob($dir.'/__save_test__*') ?: [])->toHaveCount(1);
});
