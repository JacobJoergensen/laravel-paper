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
    File::deleteDirectory(__DIR__.'/../content/docs/__nested_test__');
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

it('rejects a parent traversal segment inside a multi-segment slug', function (): void {
    Doc::find('guides/../../../config/app');
})->throws(InvalidSlugException::class);

it('creates the directory when saving a record to a new subdirectory', function (): void {
    $doc = Doc::create([
        'slug' => '__nested_test__/deep/page',
        'title' => 'Deep',
    ]);

    expect($doc->exists)->toBeTrue()
        ->and(File::exists(__DIR__.'/../content/docs/__nested_test__/deep/page.md'))->toBeTrue()
        ->and(Doc::find('__nested_test__/deep/page')?->title)->toBe('Deep');
});

it('deletes a record from a subdirectory', function (): void {
    $doc = Doc::create([
        'slug' => '__nested_test__/removable',
        'title' => 'Removable',
    ]);

    expect($doc->delete())->toBeTrue()
        ->and(Doc::find('__nested_test__/removable'))->toBeNull();
});
