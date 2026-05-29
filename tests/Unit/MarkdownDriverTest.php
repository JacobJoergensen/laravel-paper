<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;

it('returns correct extensions', function (): void {
    $driver = new MarkdownDriver;

    expect($driver->extensions())->toBe(['md', 'markdown']);
});

it('parses frontmatter and content', function (): void {
    $contents = file_get_contents(__DIR__.'/../content/posts/hello-world.md');
    $driver = new MarkdownDriver;

    $data = $driver->parse($contents);

    expect($data)
        ->toHaveKey('title', 'Hello World')
        ->toHaveKey('published', true)
        ->toHaveKey('content');
});

it('handles content without frontmatter', function (): void {
    $driver = new MarkdownDriver;
    $data = $driver->parse('Just content, no frontmatter.');

    expect($data)->toBe(['content' => 'Just content, no frontmatter.']);
});
