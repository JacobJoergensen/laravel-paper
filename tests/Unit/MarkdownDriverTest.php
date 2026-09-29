<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;

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

it('throws a paper exception naming the file when the frontmatter is malformed', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, "---\ntitle: [unclosed\n---\n\nBody.\n");

    $driver = new MarkdownDriver;

    try {
        $driver->parse($tempFile);
    } finally {
        unlink($tempFile);
    }
})->throws(FileParseException::class, 'Failed to parse frontmatter in file');

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

it('serializes nested frontmatter as block yaml that round-trips', function (): void {
    $driver = new MarkdownDriver;
    $data = [
        'title' => 'Hello',
        'seo' => ['og' => ['title' => 'T', 'tags' => ['a', 'b']]],
        'content' => 'Body',
    ];

    $serialized = $driver->serialize($data);

    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, $serialized);
    $parsed = $driver->parse($tempFile);
    unlink($tempFile);

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
    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, "Intro\n\n---\n\nMiddle\n\n---\n\nEnd\n");

    $data = new MarkdownDriver()->parse($tempFile);

    unlink($tempFile);

    expect($data)->toBe(['content' => "Intro\n\n---\n\nMiddle\n\n---\n\nEnd"]);
});

it('keeps the line endings of a body that contains a horizontal rule', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, "---\ntitle: Rule\n---\n\nAbove\n\n---\n\nBelow\n");

    $data = new MarkdownDriver()->parse($tempFile);

    unlink($tempFile);

    expect($data['content'])->toBe("Above\n\n---\n\nBelow");
});

it('keeps the indentation of the first body line', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, "---\ntitle: Code\n---\n\n    indented code\n    second line\n");

    $data = new MarkdownDriver()->parse($tempFile);

    unlink($tempFile);

    expect($data['content'])->toBe("    indented code\n    second line");
});

it('reads the frontmatter of a file saved with a byte order mark', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, "\xEF\xBB\xBF---\ntitle: Notepad\n---\n\nBody\n");

    $data = new MarkdownDriver()->parse($tempFile);

    unlink($tempFile);

    expect($data)->toBe(['title' => 'Notepad', 'content' => 'Body']);
});

it('does not read a content-only body that starts with a fence back as frontmatter', function (): void {
    $driver = new MarkdownDriver;
    $content = "---\nfoo: bar\n---\nBody";

    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, $driver->serialize(['content' => $content]));
    $parsed = $driver->parse($tempFile);
    unlink($tempFile);

    expect($parsed)->toBe(['content' => $content]);
});

it('serializes a content-only model without an empty frontmatter block', function (): void {
    $driver = new MarkdownDriver;

    $serialized = $driver->serialize(['content' => 'Body', 'slug' => 'page']);

    $tempFile = tempnam(sys_get_temp_dir(), 'md_');
    file_put_contents($tempFile, $serialized);
    $parsed = $driver->parse($tempFile);
    unlink($tempFile);

    expect($serialized)->not->toContain('---')
        ->and($parsed['content'])->toBe('Body');
});
