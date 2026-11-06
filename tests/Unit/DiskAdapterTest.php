<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\StorageAdapters\DiskAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToReadFile;

beforeEach(function (): void {
    Storage::fake('paper');
    $this->adapter = new DiskAdapter(Storage::disk('paper'), 'paper');
});

it('returns null when reading a missing file', function (): void {
    expect($this->adapter->read('missing.md'))->toBeNull();
});

it('still returns null rather than throwing on a disk configured to throw', function (): void {
    Storage::fake('strict', ['throw' => true]);
    $adapter = new DiskAdapter(Storage::disk('strict'), 'strict');

    expect($adapter->read('missing.md'))->toBeNull();
});

it('reports a failed storage check instead of reading it as a missing file', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->allows('exists')->with('unreachable.md')->andThrow(UnableToCheckExistence::forLocation('unreachable.md'));
    $disk->allows('exists')->with('unreadable.md')->andReturn(true);
    $disk->allows('get')->with('unreadable.md')->andThrow(UnableToReadFile::fromLocation('unreadable.md'));

    $adapter = new DiskAdapter($disk, 'flaky');

    expect(fn (): bool => $adapter->exists('unreachable.md'))->toThrow(UnableToCheckExistence::class)
        ->and(fn (): ?string => $adapter->read('unreadable.md'))->toThrow(UnableToReadFile::class);
});

it('round-trips contents through write and read', function (): void {
    expect($this->adapter->write('post.md', 'body'))->toBeTrue()
        ->and($this->adapter->read('post.md'))->toBe('body');
});

it('lists matching files with their modification times', function (): void {
    Storage::disk('paper')->put('articles/one.md', '');
    Storage::disk('paper')->put('articles/two.markdown', '');
    Storage::disk('paper')->put('articles/ignored.txt', '');

    $listing = $this->adapter->listing('articles', ['md', 'markdown']);
    $paths = array_keys($listing);
    sort($paths);

    expect($paths)->toBe(['articles/one.md', 'articles/two.markdown'])
        ->and($listing)->each->toBeGreaterThan(0);
});

it('leaves hidden files and folders out of the listing, like local storage', function (): void {
    Storage::disk('paper')->put('articles/one.md', '');
    Storage::disk('paper')->put('articles/.draft.md', '');
    Storage::disk('paper')->put('articles/.obsidian/notes.md', '');

    expect(array_keys($this->adapter->listing('articles', ['md'], nested: true)))->toBe(['articles/one.md']);
});

it('returns an empty list for a missing directory instead of throwing', function (): void {
    expect($this->adapter->listing('nope', ['md']))->toBe([]);
});

it('reports lastModified for a file it wrote and null for a missing one', function (): void {
    $this->adapter->write('post.md', 'body');

    expect($this->adapter->lastModified('post.md'))->toBeGreaterThan(0)
        ->and($this->adapter->lastModified('missing.md'))->toBeNull();
});

it('namespaces the cache key by disk so identical paths do not collide', function (): void {
    $other = new DiskAdapter(Storage::disk('paper'), 'backup');

    expect($this->adapter->cacheKey('articles/post.md'))
        ->not->toBe($other->cacheKey('articles/post.md'));
});
