<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\StorageAdapters\LocalAdapter;

beforeEach(function (): void {
    $this->adapter = new LocalAdapter(new Filesystem);
    $this->dir = sys_get_temp_dir().'/paper_local_adapter_'.uniqid();
    mkdir($this->dir);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('returns null when reading a missing file', function (): void {
    expect($this->adapter->read($this->dir.'/missing.md'))->toBeNull();
});

it('writes atomically via temp file and rename', function (): void {
    $path = $this->dir.'/post.md';

    expect($this->adapter->write($path, 'body'))->toBeTrue()
        ->and(file_get_contents($path))->toBe('body')
        ->and(glob($this->dir.'/.paper-*'))->toBe([]);
});

it('lists matching files with their modification times', function (): void {
    touch($this->dir.'/one.md', 1_700_000_000);
    touch($this->dir.'/two.markdown', 1_700_000_500);
    touch($this->dir.'/ignored.txt');

    $listing = $this->adapter->listing($this->dir, ['md', 'markdown']);
    $byName = collect($listing)->keyBy(fn (int $mtime, string $path): string => basename($path));

    expect($byName->keys()->sort()->values()->all())->toBe(['one.md', 'two.markdown'])
        ->and($byName['one.md'])->toBe(1_700_000_000)
        ->and($byName['two.markdown'])->toBe(1_700_000_500);
});

it('throws when listing a directory that does not exist', function (): void {
    $this->adapter->listing($this->dir.'/nope', ['md']);
})->throws(ContentPathNotFoundException::class);
