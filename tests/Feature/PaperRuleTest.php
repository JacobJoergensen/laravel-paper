<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use JacobJoergensen\LaravelPaper\Rules\PaperRule;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\ScopedPost;

beforeEach(function (): void {
    Post::resetPaperState();
    ScopedPost::resetPaperState();
});

it('accepts a value that matches an existing record', function (): void {
    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::exists(Post::class)]
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects a value that matches no record', function (): void {
    $validator = Validator::make(
        ['slug' => 'does-not-exist'],
        ['slug' => PaperRule::exists(Post::class)]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('The selected slug is invalid.');
});

it('accepts a value no record has taken', function (): void {
    $validator = Validator::make(
        ['slug' => 'brand-new-slug'],
        ['slug' => PaperRule::unique(Post::class)]
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects a value another record already has', function (): void {
    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(Post::class)]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('The slug has already been taken.');
});

it('ignores the given record but still conflicts with a different existing one', function (): void {
    $ignored = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(Post::class)->ignore('hello-world')]
    );

    $notIgnored = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(Post::class)->ignore('draft-post')]
    );

    expect($ignored->passes())->toBeTrue()
        ->and($notIgnored->fails())->toBeTrue();
});

it('ignores a record by a custom column', function (): void {
    $ignored = Validator::make(
        ['title' => 'Hello World'],
        ['title' => PaperRule::unique(Post::class, 'title')->ignore(1, 'order')]
    );

    $notIgnored = Validator::make(
        ['title' => 'Hello World'],
        ['title' => PaperRule::unique(Post::class, 'title')->ignore(2, 'order')]
    );

    expect($ignored->passes())->toBeTrue()
        ->and($notIgnored->fails())->toBeTrue();
});

it('rejects a slug taken by a record a global scope hides', function (): void {
    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(ScopedPost::class)]
    );

    expect($validator->fails())->toBeTrue();
});

it('accepts a slug held by a record a global scope hides', function (): void {
    $validator = Validator::make(
        ['slug' => 'draft-post'],
        ['slug' => PaperRule::exists(ScopedPost::class)]
    );

    expect($validator->passes())->toBeTrue();
});

it('translates the exists message to the active locale', function (): void {
    app('translator')->addLines(['validation.exists' => 'No :attribute matches that value.'], 'xx');
    app()->setLocale('xx');

    $validator = Validator::make(
        ['slug' => 'does-not-exist'],
        ['slug' => PaperRule::exists(Post::class)]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('No slug matches that value.');
});

it('translates the unique message to the active locale', function (): void {
    app('translator')->addLines(['validation.unique' => 'That :attribute is already in use.'], 'xx');
    app()->setLocale('xx');

    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(Post::class)]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('That slug is already in use.');
});
