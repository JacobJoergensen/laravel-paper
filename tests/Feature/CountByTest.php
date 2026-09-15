<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();

    $dir = __DIR__.'/../content/posts';

    file_put_contents("$dir/__count_test__a.md", "---\ntags: [laravel, php]\ncategory: guide\n---\n");
    file_put_contents("$dir/__count_test__b.md", "---\ntags: [laravel]\ncategory: guide\n---\n");
    file_put_contents("$dir/__count_test__c.md", "---\ncategory: archive\n---\n");
    file_put_contents("$dir/__count_test__d.md", "---\nseo:\n  keywords: [a, b]\n---\n");
});

afterEach(function (): void {
    foreach (glob(__DIR__.'/../content/posts/__count_test__*') ?: [] as $file) {
        @unlink($file);
    }
});

it('flattens an array column and skips records missing the field', function (): void {
    expect(Post::whereNotNull('category')->countBy('tags')->all())->toBe(['laravel' => 2, 'php' => 1]);
});

it('tallies a scalar column in first-seen order', function (): void {
    expect(Post::countBy('category')->all())->toBe(['guide' => 2, 'archive' => 1]);
});

it('skips a nested array value instead of failing on an illegal array key', function (): void {
    expect(Post::countBy('seo')->all())->toBe([]);
});

it('returns an empty collection when nothing matches', function (): void {
    expect(Post::where('category', 'missing')->countBy('tags'))->toBeEmpty();
});
