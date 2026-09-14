<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;

it('returns correct extensions', function (): void {
    $driver = new JsonDriver;

    expect($driver->extensions())->toBe(['json']);
});

it('throws when a value cannot be encoded, instead of writing an empty file', function (): void {
    $driver = new JsonDriver;

    $driver->serialize(['title' => "\xB1\x31"]);
})->throws(FileSerializeException::class, 'Malformed UTF-8');

it('reports no body column and no syntax for a data-only format', function (): void {
    $driver = new JsonDriver;

    expect($driver->bodyColumn())->toBeNull()
        ->and($driver->bodySyntax())->toBeNull();
});

it('parses json contents', function (): void {
    $contents = file_get_contents(__DIR__.'/../content/pages/about.json');
    $driver = new JsonDriver;

    $data = $driver->parse($contents);

    expect($data)
        ->toHaveKey('title', 'About Us')
        ->toHaveKey('active', true);
});

it('serializes without the slug and without escaping unicode or slashes', function (): void {
    $driver = new JsonDriver;

    $json = $driver->serialize(['slug' => 'about', 'title' => 'Café', 'url' => '/downloads/press']);

    expect(json_decode($json, true))->toBe(['title' => 'Café', 'url' => '/downloads/press'])
        ->and($json)->toContain('Café')
        ->and($json)->toContain('/downloads/press');
});

it('throws exception for invalid json', function (): void {
    $driver = new JsonDriver;
    $driver->parse('{ invalid json }');
})->throws(FileParseException::class, 'Syntax error');

it('throws when the json root is not an object', function (): void {
    $driver = new JsonDriver;
    $driver->parse('"just a string"');
})->throws(FileParseException::class, 'Root must be an object');
