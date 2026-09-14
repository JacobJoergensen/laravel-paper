<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\ExtendedManual;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\ExtendedPost;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\LegacyManual;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    Post::resetPaperState();
    ExtendedPost::resetPaperState();
    ExtendedManual::resetPaperState();
    LegacyManual::resetPaperState();
    Storage::fake('paper');
});

it('reads the content path a parent model declares', function (): void {
    expect(ExtendedPost::find('draft-post')->title)->toBe(Post::find('draft-post')->title);
});

it('reads the driver and disk a parent model declares', function (): void {
    $manual = new ExtendedManual;
    $manual->slug = 'intro';

    expect($manual->getFilePath())->toBe('manuals/intro.json');
});

it('reads the nested flag a parent model declares', function (): void {
    Storage::disk('paper')->put('manuals/guides/install.json', '{"title": "Install"}');

    expect(ExtendedManual::find('guides/install')?->title)->toBe('Install');
});

it('reads the timestamps a parent model declares', function (): void {
    Storage::disk('paper')->put('manuals/intro.json', '{"title": "Intro"}');

    expect(ExtendedManual::find('intro')?->updated_at)->not->toBeNull();
});

it('lets a child override an attribute the parent declares', function (): void {
    Storage::disk('paper')->put('archive/old.md', "---\ntitle: Old\n---\n");

    expect(LegacyManual::find('old')?->title)->toBe('Old');
});
