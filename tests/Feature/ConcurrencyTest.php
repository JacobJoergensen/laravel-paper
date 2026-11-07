<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\ConcurrencyPolicy;
use JacobJoergensen\LaravelPaper\Exceptions\StaleRecordException;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedConcurrencyException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Article;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
    Article::resetPaperState();

    $this->write = function (string $slug, string $title = 'First'): string {
        $path = base_path('tests/content/posts/').$slug.'.md';
        File::put($path, "---\ntitle: $title\n---\n\nBody\n");

        return $path;
    };
});

afterEach(function (): void {
    foreach (glob(base_path('tests/content/posts/__cc_test__*')) ?: [] as $file) {
        @unlink($file);
    }
});

it('refuses an update when the record changed after it was loaded', function (): void {
    ($this->write)('__cc_test__update');

    $first = Post::find('__cc_test__update');
    $second = Post::find('__cc_test__update');

    $first->title = 'Winner';

    expect($first->save())->toBeTrue();

    $second->title = 'Loser';
    $second->save();
})->throws(StaleRecordException::class, 'changed on disk');

it('refuses a delete when the record changed after it was loaded', function (): void {
    ($this->write)('__cc_test__delete');

    $first = Post::find('__cc_test__delete');
    $second = Post::find('__cc_test__delete');

    $first->title = 'Winner';
    $first->save();

    $second->delete();
})->throws(StaleRecordException::class);

it('reports a record that was removed while it was held', function (): void {
    ($this->write)('__cc_test__gone');

    $first = Post::find('__cc_test__gone');
    $second = Post::find('__cc_test__gone');

    $first->delete();

    $second->title = 'Ghost';
    $second->save();
})->throws(StaleRecordException::class, 'was removed from disk');

it('reports a record another delete already removed', function (): void {
    ($this->write)('__cc_test__twice');

    $first = Post::find('__cc_test__twice');
    $second = Post::find('__cc_test__twice');

    $first->delete();

    $second->delete();
})->throws(StaleRecordException::class, 'was removed from disk');

it('refuses the second of two renames of the same record', function (): void {
    ($this->write)('__cc_test__rename');

    $first = Post::find('__cc_test__rename');
    $second = Post::find('__cc_test__rename');

    $first->slug = '__cc_test__renamed_one';

    expect($first->save())->toBeTrue();

    $second->slug = '__cc_test__renamed_two';
    $second->save();
})->throws(StaleRecordException::class);

it('takes a new version from every write, so the next one is accepted', function (): void {
    ($this->write)('__cc_test__token');

    $post = Post::find('__cc_test__token');
    $post->title = 'Second';
    $post->save();

    $post->title = 'Third';

    expect($post->save())->toBeTrue()
        ->and(Post::find('__cc_test__token')->title)->toBe('Third');
});

it('takes a new version from refresh, so a stale model can write again', function (): void {
    ($this->write)('__cc_test__refresh');

    $first = Post::find('__cc_test__refresh');
    $second = Post::find('__cc_test__refresh');

    $first->title = 'Winner';
    $first->save();

    $second->refresh();
    $second->title = 'Later';

    expect($second->save())->toBeTrue()
        ->and(Post::find('__cc_test__refresh')->title)->toBe('Later');
});

it('names the stored record when a renamed model has no version to check', function (): void {
    $post = new Post;
    $post->slug = '__cc_test__stored';
    $post->exists = true;
    $post->syncOriginal();
    $post->slug = '__cc_test__renamed';

    $post->save();
})->throws(StaleRecordException::class, "'__cc_test__stored' carries no version");

it('gives a replicated model no version of its own', function (): void {
    ($this->write)('__cc_test__source');

    $copy = Post::find('__cc_test__source')->replicate();
    $copy->slug = '__cc_test__copy';

    expect($copy->save())->toBeTrue()
        ->and(Post::find('__cc_test__source'))->not->toBeNull();
});

it('writes without checking when the concurrency policy is off', function (): void {
    config(['paper.concurrency' => 'off']);

    ($this->write)('__cc_test__off');

    $first = Post::find('__cc_test__off');
    $second = Post::find('__cc_test__off');

    $first->title = 'First';
    $first->save();

    $second->title = 'Second';

    expect($second->save())->toBeTrue()
        ->and(Post::find('__cc_test__off')->title)->toBe('Second');
});

it('still undoes a rename whose old file survives when the policy is off', function (): void {
    config(['paper.concurrency' => 'off']);

    $path = PaperQueryBuilder::sourceFor(Post::class)->path;
    $adapter = new CountingAdapter;
    $adapter->seed($path.'/stuck.md', "---\ntitle: Stuck\n---\n", 1_000);
    PaperQueryBuilder::fake(Post::class, $adapter);

    $post = Post::findOrFail('stuck');
    $post->slug = 'unstuck';
    $adapter->undeletable = $path.'/stuck.md';

    expect($post->save())->toBeFalse()
        ->and($adapter->exists($path.'/unstuck.md'))->toBeFalse()
        ->and($adapter->exists($path.'/stuck.md'))->toBeTrue();
});

it('still detects a stale write on storage that cannot check atomically', function (): void {
    Storage::fake('paper');
    Storage::disk('paper')->put('articles/one.md', "---\ntitle: One\n---\n");

    $first = Article::find('one');
    $second = Article::find('one');

    $first->title = 'Winner';
    $first->save();

    $second->title = 'Loser';
    $second->save();
})->throws(StaleRecordException::class);

it('refuses non-atomic storage under the strict policy', function (ConcurrencyPolicy|string $policy): void {
    config(['paper.concurrency' => $policy]);

    Storage::fake('paper');
    Storage::disk('paper')->put('articles/one.md', "---\ntitle: One\n---\n");

    $article = Article::find('one');
    $article->title = 'Blocked';
    $article->save();
})->throws(UnsupportedConcurrencyException::class, 'cannot guarantee conditional writes')->with([
    'by name' => 'strict',
    'as an enum' => ConcurrencyPolicy::Strict,
]);

it('rejects a concurrency policy it does not know', function (mixed $policy, string $named): void {
    config(['paper.concurrency' => $policy]);

    ($this->write)('__cc_test__policy');

    $post = Post::find('__cc_test__policy');
    $post->title = 'Changed';

    expect(fn (): bool => $post->save())
        ->toThrow(UnsupportedConcurrencyException::class, "Unknown concurrency policy '$named'");
})->with([
    'unknown name' => ['maybe', 'maybe'],
    'boolean' => [false, 'false'],
]);

it('renames a record to another case of its slug when the policy is off', function (): void {
    config(['paper.concurrency' => 'off']);

    $path = ($this->write)('__cc_test__case');
    $dir = dirname($path);

    $post = Post::find('__cc_test__case');
    $post->slug = '__cc_test__CASE';

    expect($post->save())->toBeTrue()
        ->and(glob($dir.'/__cc_test__*'))->toBe([$dir.'/__cc_test__CASE.md'])
        ->and(Post::find('__cc_test__CASE')?->title)->toBe('First');
});

it('keeps record locks in the app storage directory, not the system temp directory', function (): void {
    File::deleteDirectory(storage_path('framework/cache/paper'));
    $tempLocks = glob(sys_get_temp_dir().'/*.lock') ?: [];

    ($this->write)('__cc_test__lock');

    $post = Post::find('__cc_test__lock');
    $post->title = 'Locked';
    $post->save();

    $newTempLocks = array_diff(glob(sys_get_temp_dir().'/*.lock') ?: [], $tempLocks);

    expect($newTempLocks)->toBe([])
        ->and(glob(storage_path('framework/cache/paper/*.lock')))->toHaveCount(1);
});
