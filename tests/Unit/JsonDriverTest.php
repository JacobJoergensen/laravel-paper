<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;

it('returns correct extensions', function (): void {
    $driver = new JsonDriver;

    expect($driver->extensions())->toBe(['json']);
});

it('parses json contents', function (): void {
    $contents = file_get_contents(__DIR__.'/../content/pages/about.json');
    $driver = new JsonDriver;

    $data = $driver->parse($contents);

    expect($data)
        ->toHaveKey('title', 'About Us')
        ->toHaveKey('active', true);
});

it('throws exception for invalid json', function (): void {
    new JsonDriver()->parse('{ invalid json }');
})->throws(FileParseException::class);

it('throws when the json root is not an object', function (): void {
    new JsonDriver()->parse('"just a string"');
})->throws(FileParseException::class);
