<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

function fakePostAdapter(): CountingAdapter
{
    $dir = PaperQueryBuilder::contentPathFor(Post::class);
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

it('fails when any argument is not a Paper model', function (string $command): void {
    $this->artisan($command, ['model' => [Post::class, stdClass::class]])
        ->assertFailed()
        ->expectsOutputToContain('is not a Paper model');
})->with(['paper:warm', 'paper:clear', 'paper:refresh']);
