<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Draft;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Page;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
    Page::resetPaperState();
});

afterEach(function (): void {
    foreach (glob(__DIR__.'/../content/posts/__save_test__*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob(__DIR__.'/../content/pages/__json_save_test__*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob(__DIR__.'/../content/*/.paper-*') ?: [] as $file) {
        @unlink($file);
    }
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

it('writes and reads back a json model', function (): void {
    $page = new Page;
    $page->slug = '__json_save_test__';
    $page->title = 'Round Trip';
    $page->active = true;
    $page->save();

    $loaded = Page::find('__json_save_test__');

    expect($loaded)->not->toBeNull()
        ->and($loaded->title)->toBe('Round Trip')
        ->and($loaded->active)->toBeTrue();
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

it('mass updates only the records matching the query, bypassing fillable', function (): void {
    foreach (['a' => 'bulk', 'b' => 'bulk', 'c' => 'other'] as $key => $group) {
        $post = new Post;
        $post->slug = "__save_test__bulk_$key";
        $post->group = $group;
        $post->status = 'draft';
        $post->save();
    }

    $count = Post::where('group', 'bulk')->update(['status' => 'published']);

    expect($count)->toBe(2)
        ->and(Post::find('__save_test__bulk_a')->status)->toBe('published')
        ->and(Post::find('__save_test__bulk_c')->status)->toBe('draft');
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
