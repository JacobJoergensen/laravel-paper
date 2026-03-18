<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Page;

beforeEach(function (): void {
    Page::resetPaperState();
});

it('can find a page by slug', function (): void {
    $page = Page::find('about');

    expect($page)->not->toBeNull()
        ->and($page->slug)->toBe('about')
        ->and($page->title)->toBe('About Us');
});

it('can get all pages', function (): void {
    $pages = Page::all();

    expect($pages)->toHaveCount(2);
});
