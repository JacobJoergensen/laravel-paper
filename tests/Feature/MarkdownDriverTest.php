<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Author;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Draft;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

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
    expect(Post::whereLike('title', '%post%')->count())->toBe(2);

    expect(Post::whereLike('title', '%post%', caseSensitive: true)->count())->toBe(0)
        ->and(Post::whereLike('title', '%Post%', caseSensitive: true)->count())->toBe(2);

    expect(Post::whereLike('title', 'Hello')->count())->toBe(0)
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

it('returns the first record matching a where condition', function (): void {
    $post = Post::firstWhere('slug', 'hello-world');

    expect($post)->not->toBeNull()
        ->and($post->slug)->toBe('hello-world');

    expect(Post::firstWhere('slug', 'does-not-exist'))->toBeNull();
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
