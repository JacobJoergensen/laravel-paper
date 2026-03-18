<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;

it('returns correct extensions', function (): void {
    $driver = new JsonDriver;

    expect($driver->extensions())->toBe(['json']);
});

it('parses json file', function (): void {
    $filepath = __DIR__.'/../content/pages/about.json';
    $driver = new JsonDriver;

    $data = $driver->parse($filepath);

    expect($data)
        ->toHaveKey('title', 'About Us')
        ->toHaveKey('active', true);
});

it('throws exception for invalid json', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'json_');
    file_put_contents($tempFile, '{ invalid json }');

    $driver = new JsonDriver;

    try {
        $driver->parse($tempFile);
    } finally {
        unlink($tempFile);
    }
})->throws(FileParseException::class);

it('throws exception for unreadable file', function (): void {
    $driver = new JsonDriver;
    $driver->parse('/nonexistent/file.json');
})->throws(FileParseException::class);
