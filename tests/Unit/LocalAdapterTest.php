<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\PaperVersion;
use JacobJoergensen\LaravelPaper\StorageAdapters\ConditionalWriteStatus;
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

it('creates only when nothing holds the path', function (): void {
    $path = $this->dir.'/post.md';

    $created = $this->adapter->createIfMissing($path, 'first');
    $again = $this->adapter->createIfMissing($path, 'second');

    expect($created->status)->toBe(ConditionalWriteStatus::Written)
        ->and($created->version)->toBe(PaperVersion::of('first'))
        ->and($again->status)->toBe(ConditionalWriteStatus::Taken)
        ->and(file_get_contents($path))->toBe('first');
});

it('replaces only the version it was given', function (): void {
    $path = $this->dir.'/post.md';
    $this->adapter->write($path, 'first');

    $stale = $this->adapter->replaceIf($path, 'stale', PaperVersion::of('other'));
    $fresh = $this->adapter->replaceIf($path, 'second', PaperVersion::of('first'));
    $gone = $this->adapter->replaceIf($this->dir.'/missing.md', 'x', PaperVersion::of('first'));

    expect($stale->status)->toBe(ConditionalWriteStatus::Mismatch)
        ->and($fresh->status)->toBe(ConditionalWriteStatus::Written)
        ->and($gone->status)->toBe(ConditionalWriteStatus::Missing)
        ->and(file_get_contents($path))->toBe('second');
});

it('deletes only the version it was given', function (): void {
    $path = $this->dir.'/post.md';
    $this->adapter->write($path, 'first');

    $stale = $this->adapter->deleteIf($path, PaperVersion::of('other'));

    expect($stale->status)->toBe(ConditionalWriteStatus::Mismatch)
        ->and(file_exists($path))->toBeTrue()
        ->and($this->adapter->deleteIf($path, PaperVersion::of('first'))->status)->toBe(ConditionalWriteStatus::Removed)
        ->and(file_exists($path))->toBeFalse();
});

it('moves a record only when the source matches and the destination is free', function (): void {
    $from = $this->dir.'/from.md';
    $to = $this->dir.'/to.md';
    $this->adapter->write($from, 'body');

    $stale = $this->adapter->moveIf($from, $to, 'body', PaperVersion::of('other'));

    expect($stale->status)->toBe(ConditionalWriteStatus::Mismatch)
        ->and(file_exists($to))->toBeFalse();

    $this->adapter->write($to, 'taken');

    expect($this->adapter->moveIf($from, $to, 'body', PaperVersion::of('body'))->status)->toBe(ConditionalWriteStatus::Taken)
        ->and(file_get_contents($to))->toBe('taken');

    unlink($to);

    expect($this->adapter->moveIf($from, $to, 'body', PaperVersion::of('body'))->status)->toBe(ConditionalWriteStatus::Written)
        ->and(file_exists($from))->toBeFalse()
        ->and(file_get_contents($to))->toBe('body');
});

it('refuses a create when a conflicting path is already held', function (): void {
    $md = $this->dir.'/post.md';
    $markdown = $this->dir.'/post.markdown';

    $this->adapter->createIfMissing($md, 'one');
    $result = $this->adapter->createIfMissing($markdown, 'two', [$md]);

    expect($result->status)->toBe(ConditionalWriteStatus::Taken)
        ->and($result->path)->toBe($md)
        ->and(file_exists($markdown))->toBeFalse();
});

it('takes one lock per record, whichever extension the file has', function (): void {
    $before = glob(sys_get_temp_dir().'/paper-*.lock') ?: [];

    $this->adapter->createIfMissing($this->dir.'/post.md', 'one');
    $this->adapter->createIfMissing($this->dir.'/post.markdown', 'two', [$this->dir.'/post.md']);
    $this->adapter->createIfMissing($this->dir.'/other.md', 'three');

    $created = array_diff(glob(sys_get_temp_dir().'/paper-*.lock') ?: [], $before);

    expect($created)->toHaveCount(2);
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

    expect($paths)->toEqualCanonicalizing([$this->dir.'/root.md', $this->dir.'/sub/child.md']);
});

it('throws when listing a directory that does not exist', function (): void {
    $this->adapter->listing($this->dir.'/nope', ['md']);
})->throws(ContentPathNotFoundException::class);
