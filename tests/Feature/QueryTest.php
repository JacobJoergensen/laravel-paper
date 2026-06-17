<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Draft;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
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

it('finds many posts by slug, omitting missing and duplicate ids', function (): void {
    $posts = Post::findMany(['hello-world', 'does-not-exist', 'second-post', 'hello-world']);

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('slug')->sort()->values()->toArray())->toBe(['hello-world', 'second-post']);
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

it('treats the first orderBy as primary and later ones as tiebreakers', function (): void {
    $posts = Post::query()->orderBy('published')->orderBy('date')->get();

    expect($posts->pluck('slug')->toArray())->toBe(['draft-post', 'hello-world', 'second-post']);
});

it('returns one model per slug when the same slug exists under multiple extensions', function (): void {
    $duplicate = __DIR__.'/../content/posts/hello-world.markdown';
    File::put($duplicate, File::get(__DIR__.'/../content/posts/hello-world.md'));

    try {
        $posts = Post::all();

        expect($posts)->toHaveCount(3)
            ->and($posts->where('slug', 'hello-world'))->toHaveCount(1);
    } finally {
        File::delete($duplicate);
    }
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

it('returns records in a stable slug order across pages', function (): void {
    $slugs = collect([1, 2, 3])
        ->map(fn (int $page): string => Post::paginate(perPage: 1, page: $page)->first()->slug)
        ->all();

    expect($slugs)->toBe(['draft-post', 'hello-world', 'second-post']);
});

it('paginates correctly when ordering by a frontmatter field', function (): void {
    $page = Post::query()->orderByDesc('order')->paginate(perPage: 1, page: 1);

    expect($page->first()->slug)->toBe('draft-post')
        ->and($page->total())->toBe(3);
});

it('paginates a filtered query with the correct total', function (): void {
    $page = Post::where('published', true)->paginate(perPage: 1, page: 1);

    expect($page->total())->toBe(2);
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

it('matches whereIn loosely so frontmatter type quirks do not exclude records', function (): void {
    expect(Post::whereIn('order', ['1'])->pluck('slug')->toArray())->toBe(['hello-world'])
        ->and(Post::whereNotIn('order', ['1'])->count())->toBe(2);
});

it('filters with whereBetween and whereNotBetween', function (): void {
    expect(Post::whereBetween('order', [1, 2])->count())->toBe(2)
        ->and(Post::whereNotBetween('order', [1, 2])->count())->toBe(1);
});

it('excludes records missing the column from whereBetween and whereNotBetween', function (): void {
    expect(Post::whereBetween('author_slug', ['a', 'z'])->count())->toBe(1)
        ->and(Post::whereNotBetween('author_slug', ['a', 'z'])->count())->toBe(0);
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

it('keys plucked values by a second column', function (): void {
    expect(Post::query()->pluck('order', 'slug')->toArray())
        ->toBe(['draft-post' => 3, 'hello-world' => 1, 'second-post' => 2]);
});

it('returns an existing record or a new unsaved instance with firstOrNew', function (): void {
    $existing = Post::firstOrNew(['slug' => 'hello-world']);
    $fresh = Post::firstOrNew(['slug' => 'ghost'], ['title' => 'Ghost']);

    expect($existing->exists)->toBeTrue()
        ->and($existing->title)->toBe('Hello World')
        ->and($fresh->exists)->toBeFalse()
        ->and($fresh->title)->toBe('Ghost')
        ->and(Post::find('ghost'))->toBeNull();
});

it('returns the model or the callback result with findOr', function (): void {
    expect(Post::findOr('hello-world', fn (): string => 'fallback')->slug)->toBe('hello-world')
        ->and(Post::findOr('ghost', fn (): string => 'fallback'))->toBe('fallback');
});

it('returns the first match or the callback result with firstOr', function (): void {
    expect(Post::where('slug', 'hello-world')->firstOr(fn (): string => 'fallback')->slug)->toBe('hello-world')
        ->and(Post::where('slug', 'ghost')->firstOr(fn (): string => 'fallback'))->toBe('fallback');
});

it('processes records in chunks and stops when the callback returns false', function (): void {
    $slugs = [];

    Post::query()->chunk(2, function ($models) use (&$slugs): void {
        foreach ($models as $model) {
            $slugs[] = $model->slug;
        }
    });

    $chunks = 0;

    Post::query()->chunk(1, function () use (&$chunks): bool {
        $chunks++;

        return false;
    });

    expect($slugs)->toBe(['draft-post', 'hello-world', 'second-post'])
        ->and($chunks)->toBe(1);
});

it('iterates every record with each and stops when the callback returns false', function (): void {
    $slugs = [];

    Post::query()->each(function ($model) use (&$slugs): void {
        $slugs[] = $model->slug;
    });

    $seen = 0;

    Post::query()->each(function () use (&$seen): bool {
        $seen++;

        return false;
    });

    expect($slugs)->toBe(['draft-post', 'hello-world', 'second-post'])
        ->and($seen)->toBe(1);
});

it('reports more pages without counting every record', function (): void {
    $first = Post::query()->simplePaginate(perPage: 2, page: 1);
    $second = Post::query()->simplePaginate(perPage: 2, page: 2);

    expect($first)->toHaveCount(2)
        ->and($first->hasMorePages())->toBeTrue()
        ->and($second)->toHaveCount(1)
        ->and($second->hasMorePages())->toBeFalse();
});

it('throws when querying a model whose content directory is missing', function (): void {
    File::deleteDirectory(__DIR__.'/../content/drafts');

    Draft::resetPaperState();

    Draft::all();
})->throws(ContentPathNotFoundException::class);
