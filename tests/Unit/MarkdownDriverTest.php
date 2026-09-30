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

it('writes an empty list as a list', function (): void {
    $driver = new MarkdownDriver;

    expect($driver->serialize(['tags' => [], 'content' => 'Body']))->toContain("tags: []\n");
});

it('keeps a body without frontmatter whole when it contains horizontal rules', function (): void {
    $data = new MarkdownDriver()->parse("Intro\n\n---\n\nMiddle\n\n---\n\nEnd\n");

    expect($data)->toBe(['content' => "Intro\n\n---\n\nMiddle\n\n---\n\nEnd"]);
});

it('keeps the line endings of a body that contains a horizontal rule', function (): void {
    $data = new MarkdownDriver()->parse("---\ntitle: Rule\n---\n\nAbove\n\n---\n\nBelow\n");

    expect($data['content'])->toBe("Above\n\n---\n\nBelow");
});

it('keeps the indentation of the first body line', function (): void {
    $data = new MarkdownDriver()->parse("---\ntitle: Code\n---\n\n    indented code\n    second line\n");

    expect($data['content'])->toBe("    indented code\n    second line");
});

it('reads the frontmatter of a file saved with a byte order mark', function (): void {
    $data = new MarkdownDriver()->parse("\xEF\xBB\xBF---\ntitle: Notepad\n---\n\nBody\n");

    expect($data)->toBe(['title' => 'Notepad', 'content' => 'Body']);
});

it('does not read a content-only body that starts with a fence back as frontmatter', function (): void {
    $driver = new MarkdownDriver;
    $content = "---\nfoo: bar\n---\nBody";

    $parsed = $driver->parse($driver->serialize(['content' => $content]));

    expect($parsed)->toBe(['content' => $content]);
});

it('serializes a content-only model without an empty frontmatter block', function (): void {
    $driver = new MarkdownDriver;

    $serialized = $driver->serialize(['content' => 'Body', 'slug' => 'page']);
    $parsed = $driver->parse($serialized);

    expect($serialized)->not->toContain('---')
        ->and($parsed['content'])->toBe('Body');
});
