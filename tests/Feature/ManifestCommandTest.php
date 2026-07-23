<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

it('warms the manifest for a Paper model', function (): void {
    $this->artisan('paper:cache', ['model' => [Post::class]])->assertSuccessful();
});

it('clears the manifest for a Paper model', function (): void {
    $this->artisan('paper:clear', ['model' => [Post::class]])->assertSuccessful();
});

it('clears and rebuilds the manifest for a Paper model', function (): void {
    $this->artisan('paper:refresh', ['model' => [Post::class]])->assertSuccessful();
});

it('fails when given a class that is not a Paper model', function (): void {
    $this->artisan('paper:cache', ['model' => [stdClass::class]])->assertFailed();
});
