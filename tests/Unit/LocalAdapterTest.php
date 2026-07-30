<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\StorageAdapters\LocalAdapter;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->adapter = new LocalAdapter($this->files);
    $this->dir = sys_get_temp_dir().'/paper_local_adapter_'.uniqid();
    mkdir($this->dir);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->dir);
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

it('skips dotfiles so hidden entries never become records', function (): void {
    touch($this->dir.'/visible.md');
    touch($this->dir.'/.hidden.md');

    expect(array_keys($this->adapter->listing($this->dir, ['md'])))->toBe([$this->dir.'/visible.md']);
});

it('reports lastModified for a file it wrote and null for a missing one', function (): void {
    $path = $this->dir.'/post.md';
    $this->adapter->write($path, 'body');

    expect($this->adapter->lastModified($path))->toBeGreaterThan(0)
        ->and($this->adapter->lastModified($this->dir.'/missing.md'))->toBeNull();
});

it('lists each file once when a subdirectory links back into the tree', function (): void {
    mkdir($this->dir.'/sub');
    touch($this->dir.'/root.md');
    touch($this->dir.'/sub/child.md');

    if (! @symlink($this->dir, $this->dir.'/sub/loop')) {
        $this->markTestSkipped('the platform does not allow creating a symlink');
    }

    $paths = array_keys($this->adapter->listing($this->dir, ['md'], nested: true));

    expect(array_map(basename(...), $paths))->toEqualCanonicalizing(['root.md', 'child.md']);
});

it('throws when listing a directory that does not exist', function (): void {
    $this->adapter->listing($this->dir.'/nope', ['md']);
})->throws(ContentPathNotFoundException::class);
