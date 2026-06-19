<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\StorageAdapters\DiskAdapter;

beforeEach(function (): void {
    Storage::fake('paper');
    $this->adapter = new DiskAdapter(Storage::disk('paper'), 'paper');
});

it('returns null when reading a missing file', function (): void {
    expect($this->adapter->read('missing.md'))->toBeNull();
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
    $basenames = array_map(fn (string $path): string => basename($path), array_keys($listing));
    sort($basenames);

    expect($basenames)->toBe(['one.md', 'two.markdown'])
        ->and($listing)->each->toBeInt();
});

it('returns an empty list for a missing directory instead of throwing', function (): void {
    expect($this->adapter->listing('nope', ['md']))->toBe([]);
});

it('returns null for lastModified when the file is missing', function (): void {
    expect($this->adapter->lastModified('missing.md'))->toBeNull();
});

it('namespaces the cache key by disk so identical paths do not collide', function (): void {
    $other = new DiskAdapter(Storage::disk('paper'), 'backup');

    expect($this->adapter->cacheKey('articles/post.md'))
        ->not->toBe($other->cacheKey('articles/post.md'));
});
