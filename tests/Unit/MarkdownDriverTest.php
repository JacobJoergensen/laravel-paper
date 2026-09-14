<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;

it('returns correct extensions', function (): void {
    $driver = new MarkdownDriver;

    expect($driver->extensions())->toBe(['md', 'markdown']);
});

it('exposes the body column and the syntax it is written in', function (): void {
    $driver = new MarkdownDriver;

    expect($driver->bodyColumn())->toBe('content')
        ->and($driver->bodySyntax())->toBe('markdown');
});

it('parses frontmatter and content', function (): void {
    $contents = file_get_contents(__DIR__.'/../content/posts/hello-world.md');
    $driver = new MarkdownDriver;

    $data = $driver->parse($contents);

    expect($data)
        ->toHaveKey('title', 'Hello World')
        ->toHaveKey('published', true)
        ->toHaveKey('content', 'This is my first post. Welcome to the blog!');
});

it('handles content without frontmatter', function (): void {
    $driver = new MarkdownDriver;
    $data = $driver->parse('Just content, no frontmatter.');

    expect($data)->toBe(['content' => 'Just content, no frontmatter.']);
});

it('throws a Paper exception when the frontmatter is malformed', function (): void {
    $driver = new MarkdownDriver;
    $driver->parse("---\ntitle: [unclosed\n---\nBody");
})->throws(FileParseException::class, 'Failed to parse frontmatter');

it('serializes nested frontmatter as block yaml that round-trips', function (): void {
    $driver = new MarkdownDriver;
    $data = [
        'title' => 'Hello',
        'seo' => ['og' => ['title' => 'T', 'tags' => ['a', 'b']]],
        'content' => 'Body',
    ];

    $serialized = $driver->serialize($data);
    $parsed = $driver->parse($serialized);

    expect($serialized)->not->toContain('{')
        ->and($parsed['seo'])->toBe(['og' => ['title' => 'T', 'tags' => ['a', 'b']]]);
});

it('throws for a frontmatter value it cannot represent, instead of writing null', function (): void {
    $driver = new MarkdownDriver;
    $handle = fopen('php://memory', 'r');

    try {
        expect(fn (): string => $driver->serialize(['handle' => $handle]))
            ->toThrow(FileSerializeException::class, 'Unable to dump PHP resources');
    } finally {
        fclose($handle);
    }
});

it('serializes a content-only model without an empty frontmatter block', function (): void {
    $driver = new MarkdownDriver;

    $serialized = $driver->serialize(['content' => 'Body', 'slug' => 'page']);
    $parsed = $driver->parse($serialized);

    expect($serialized)->not->toContain('---')
        ->and($parsed['content'])->toBe('Body');
});
