<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\BrokenModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

function fakePostAdapter(): CountingAdapter
{
    $dir = PaperQueryBuilder::sourceFor(Post::class)->path;
    $adapter = new CountingAdapter;
    $adapter->seed($dir.'/cmd-1.md', "---\ntitle: One\n---\n", 1_000);
    $adapter->seed($dir.'/cmd-2.md', "---\ntitle: Two\n---\n", 2_000);
    PaperQueryBuilder::fake(Post::class, $adapter);

    return $adapter;
}

it('warms the manifest so a later query serves from cache without reading files', function (): void {
    $adapter = fakePostAdapter();

    $this->artisan('paper:warm', ['model' => [Post::class]])->assertSuccessful();

    $adapter->reset();
    Post::all();

    expect($adapter->counts['read'])->toBe(0);
});

it('clears the manifest so a later query reads the files again', function (): void {
    $adapter = fakePostAdapter();

    $this->artisan('paper:warm', ['model' => [Post::class]])->assertSuccessful();
    $this->artisan('paper:clear', ['model' => [Post::class]])->assertSuccessful();

    $adapter->reset();
    Post::all();

    expect($adapter->counts['read'])->toBeGreaterThan(0);
});

it('refreshes by rebuilding the manifest and leaving it warm', function (): void {
    $adapter = fakePostAdapter();

    $this->artisan('paper:warm', ['model' => [Post::class]])->assertSuccessful();
    $adapter->reset();

    $this->artisan('paper:refresh', ['model' => [Post::class]])->assertSuccessful();
    $rebuildReads = $adapter->counts['read'];

    $adapter->reset();
    Post::all();

    expect($rebuildReads)->toBeGreaterThan(0)
        ->and($adapter->counts['read'])->toBe(0);
});

it('covers every Paper model in app/Models when none are named', function (): void {
    $this->app->useAppPath(dirname(__DIR__).'/Fixtures/DiscoveryApp');

    $this->artisan('paper:clear')
        ->assertSuccessful()
        ->expectsOutputToContain('DiscoveredPost: manifest cleared.');
});

it('reports a malformed file instead of failing with an unhandled exception', function (): void {
    $this->artisan('paper:warm', ['model' => [BrokenModel::class]])
        ->assertFailed()
        ->expectsOutputToContain('broken-yaml.md');
});

it('rejects the whole run when one argument is not a Paper model', function (string $model): void {
    $this->artisan('paper:warm', ['model' => [Post::class, $model]])
        ->assertExitCode(2)
        ->expectsOutputToContain('is not a Paper model');
})->with([
    'any other class' => stdClass::class,
    'the abstract base class' => PaperModel::class,
]);

it('clears the manifest of the current request, not the one the console app was built in', function (): void {
    $adapter = fakePostAdapter();

    $this->artisan('paper:warm', ['model' => [Post::class]])->assertSuccessful();
    $this->app->forgetScopedInstances();
    Post::all();

    $this->artisan('paper:clear', ['model' => [Post::class]])->assertSuccessful();
    $adapter->reset();
    Post::all();

    expect($adapter->counts['read'])->toBeGreaterThan(0);
});

it('fails instead of silently passing when the app has no Paper models', function (): void {
    $this->artisan('paper:warm')
        ->assertExitCode(2)
        ->expectsOutputToContain('No Paper models found');
});
