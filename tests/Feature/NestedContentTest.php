<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Doc;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\FlatDoc;

beforeEach(function (): void {
    Doc::resetPaperState();
    FlatDoc::resetPaperState();
});

afterEach(function (): void {
    File::deleteDirectory(base_path('tests/content/docs/__nested_test__'));
});

it('resolves a record from a multi-segment slug', function (): void {
    expect(Doc::find('guides/installation')?->title)->toBe('Installation');
});

it('lists records from every depth below the content path', function (): void {
    expect(Doc::pluck('slug')->all())->toBe([
        'guides/advanced/caching',
        'guides/installation',
        'index',
    ]);
});

it('ignores subdirectories when the content path is not nested', function (): void {
    expect(FlatDoc::pluck('slug')->all())->toBe(['index']);
});

it('creates the directory when saving a record to a new subdirectory', function (): void {
    $doc = Doc::create([
        'slug' => '__nested_test__/deep/page',
        'title' => 'Deep',
    ]);

    expect($doc->exists)->toBeTrue()
        ->and(File::exists(base_path('tests/content/docs/__nested_test__/deep/page.md')))->toBeTrue()
        ->and(Doc::find('__nested_test__/deep/page')?->title)->toBe('Deep');
});

it('rejects a slug Windows cannot store when saving', function (string $slug): void {
    Doc::create(['slug' => "__nested_test__/$slug", 'title' => 'Nope']);
})->throws(InvalidSlugException::class, 'not a valid filename on Windows')->with([
    'illegal character' => 'a:b',
    'reserved name' => 'con',
    'reserved name with extension' => 'nul.backup',
    'reserved superscript name' => 'lpt¹',
    'reserved directory' => 'com1/intro',
    'directory ending with a dot' => 'guides./intro',
    'directory ending with a space' => 'guides /intro',
])->onlyOnWindows();

it('saves a slug that looks like a reserved Windows name but is not one', function (string $slug): void {
    $doc = Doc::create(['slug' => "__nested_test__/$slug", 'title' => 'Allowed']);

    expect($doc->exists)->toBeTrue();
})->with([
    'reserved name as a prefix' => 'console/com10',
    'serial port zero' => 'com0',
    'printer port zero' => 'lpt0',
])->onlyOnWindows();

it('deletes a record from a subdirectory', function (): void {
    $doc = Doc::create([
        'slug' => '__nested_test__/removable',
        'title' => 'Removable',
    ]);

    expect($doc->delete())->toBeTrue()
        ->and(Doc::find('__nested_test__/removable'))->toBeNull();
});
