<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TenantPost;

beforeEach(function (): void {
    Post::resetPaperState();
    TenantPost::resetPaperState();
    TenantPost::$tenant = 'a';
});

it('resolves the content path per call so it can vary at runtime', function (): void {
    TenantPost::$tenant = 'a';
    $a = TenantPost::find('hello');

    TenantPost::$tenant = 'b';
    $b = TenantPost::find('hello');

    expect($a->title)->toBe('Tenant A Hello')
        ->and($b->title)->toBe('Tenant B Hello');
});

it('reports the file extension the record was loaded with', function (): void {
    expect(Post::find('draft-post')->getFilePath())->toEndWith('posts/draft-post.markdown');
});
