<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\YamlDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;

it('returns correct extensions', function (): void {
    $driver = new YamlDriver;

    expect($driver->extensions())->toBe(['yaml', 'yml']);
});

it('throws for a value it cannot represent, instead of writing null', function (): void {
    $driver = new YamlDriver;
    $handle = fopen('php://memory', 'r');

    try {
        expect(fn (): string => $driver->serialize(['handle' => $handle]))
            ->toThrow(FileSerializeException::class, 'Unable to dump PHP resources');
    } finally {
        fclose($handle);
    }
});

it('parses yaml file', function (): void {
    $filepath = __DIR__.'/../content/team/alex.yaml';
    $driver = new YamlDriver;

    $data = $driver->parse($filepath);

    expect($data)
        ->toHaveKey('name', 'Alex Rivera')
        ->toHaveKey('active', true)
        ->toHaveKey('bio', "Joined in 2019.\nWorks on the storage layer.")
        ->toHaveKey('skills', ['php', 'laravel']);
});

it('serializes multi-line strings as literal blocks and drops the slug', function (): void {
    $driver = new YamlDriver;

    $yaml = $driver->serialize(['slug' => 'alex', 'bio' => "One.\nTwo.", 'skills' => ['php']]);

    $tempFile = tempnam(sys_get_temp_dir(), 'yaml_');
    file_put_contents($tempFile, $yaml);
    $parsed = $driver->parse($tempFile);
    unlink($tempFile);

    expect($yaml)->toContain('bio: |-')
        ->and($parsed)->toBe(['bio' => "One.\nTwo.", 'skills' => ['php']]);
});

it('ends the file with a newline when the last value is a literal block', function (): void {
    $driver = new YamlDriver;

    expect($driver->serialize(['title' => 'T', 'bio' => "One.\nTwo."]))->toEndWith("Two.\n");
});

it('treats an empty document as a record with no fields', function (): void {
    $driver = new YamlDriver;

    $tempFile = tempnam(sys_get_temp_dir(), 'yaml_');
    file_put_contents($tempFile, $driver->serialize(['slug' => 'alex']));
    $parsed = $driver->parse($tempFile);
    unlink($tempFile);

    expect($parsed)->toBe([]);
});

it('throws a paper exception naming the file when the yaml is malformed', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'yaml_');
    file_put_contents($tempFile, "name: [unclosed\nrole: Engineer");

    $driver = new YamlDriver;

    try {
        $driver->parse($tempFile);
    } finally {
        unlink($tempFile);
    }
})->throws(FileParseException::class, 'Failed to parse YAML file');

it('throws when the yaml root is not a mapping', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'yaml_');
    file_put_contents($tempFile, 'just a string');

    $driver = new YamlDriver;

    try {
        $driver->parse($tempFile);
    } finally {
        unlink($tempFile);
    }
})->throws(FileParseException::class, 'Root must be a mapping');

it('throws exception for unreadable file', function (): void {
    $driver = new YamlDriver;
    $driver->parse('/nonexistent/file.yaml');
})->throws(FileParseException::class);
