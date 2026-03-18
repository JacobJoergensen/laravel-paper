<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;

it('returns correct extensions', function (): void {
    $driver = new MarkdownDriver;

    expect($driver->extensions())->toBe(['md', 'markdown']);
});

it('parses frontmatter and content', function (): void {
    $filepath = __DIR__.'/../content/posts/hello-world.md';
    $driver = new MarkdownDriver;

    $data = $driver->parse($filepath);

    expect($data)
        ->toHaveKey('title', 'Hello World')
        ->toHaveKey('published', true)
        ->toHaveKey('content');
});

it('handles files without frontmatter', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, 'Just content, no frontmatter.');

    $driver = new MarkdownDriver;
    $data = $driver->parse($tempFile);

    unlink($tempFile);

    expect($data)->toBe(['content' => 'Just content, no frontmatter.']);
});

it('throws exception for unreadable file', function (): void {
    $driver = new MarkdownDriver;
    $driver->parse('/nonexistent/file.md');
})->throws(FileParseException::class);
