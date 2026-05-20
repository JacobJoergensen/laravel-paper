<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\Page;

beforeEach(function (): void {
    Page::resetPaperState();
});

afterEach(function (): void {
    $dir = __DIR__.'/../content/pages';

    foreach (glob($dir.'/__json_save_test__*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob($dir.'/.paper-*') ?: [] as $file) {
        @unlink($file);
    }
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

it('writes and reads back a json model', function (): void {
    $page = new Page;
    $page->slug = '__json_save_test__';
    $page->title = 'Round Trip';
    $page->active = true;
    $page->save();

    $loaded = Page::find('__json_save_test__');

    expect($loaded)->not->toBeNull()
        ->and($loaded->title)->toBe('Round Trip')
        ->and($loaded->active)->toBeTrue();
});
