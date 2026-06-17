<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use JacobJoergensen\LaravelPaper\Rules\PaperRule;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
});

it('validates exists rule passes for existing model', function (): void {
    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::exists(Post::class)]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates exists rule fails for non-existing model', function (): void {
    $validator = Validator::make(
        ['slug' => 'does-not-exist'],
        ['slug' => PaperRule::exists(Post::class)]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('The selected slug is invalid.');
});

it('validates unique rule passes for new value', function (): void {
    $validator = Validator::make(
        ['slug' => 'brand-new-slug'],
        ['slug' => PaperRule::unique(Post::class)]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates unique rule fails for existing value', function (): void {
    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(Post::class)]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('The slug has already been taken.');
});

it('validates unique rule with ignore passes for same model', function (): void {
    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(Post::class)->ignore('hello-world')]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates unique rule with ignore fails for different existing model', function (): void {
    $validator = Validator::make(
        ['slug' => 'hello-world'],
        ['slug' => PaperRule::unique(Post::class)->ignore('draft-post')]
    );

    expect($validator->fails())->toBeTrue();
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
