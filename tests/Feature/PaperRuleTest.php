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
        ->and($validator->errors()->first('slug'))->toBe('The selected slug does not exist.');
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
