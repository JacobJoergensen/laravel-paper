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

it('lists only files matching the requested extensions', function (): void {
    touch($this->dir.'/one.md');
    touch($this->dir.'/two.markdown');
    touch($this->dir.'/ignored.txt');

    $basenames = array_map(fn (string $path): string => basename($path), $this->adapter->list($this->dir, ['md', 'markdown']));
    sort($basenames);

    expect($basenames)->toBe(['one.md', 'two.markdown']);
});

it('throws when listing a directory that does not exist', function (): void {
    $this->adapter->list($this->dir.'/nope', ['md']);
})->throws(ContentPathNotFoundException::class);
