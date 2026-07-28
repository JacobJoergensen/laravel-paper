<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\YamlDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;

it('returns correct extensions', function (): void {
    $driver = new YamlDriver;

    expect($driver->extensions())->toBe(['yaml', 'yml']);
});

it('parses yaml contents', function (): void {
    $contents = file_get_contents(__DIR__.'/../content/team/alex.yaml');
    $driver = new YamlDriver;

    $data = $driver->parse($contents);

    expect($data)
        ->toHaveKey('name', 'Alex Rivera')
        ->toHaveKey('active', true)
        ->toHaveKey('bio', "Joined in 2019.\nWorks on the storage layer.")
        ->toHaveKey('skills', ['php', 'laravel']);
});

it('serializes multi-line strings as literal blocks and drops the slug', function (): void {
    $driver = new YamlDriver;

    $yaml = $driver->serialize(['slug' => 'alex', 'bio' => "One.\nTwo.", 'skills' => ['php']]);

    expect($yaml)->toContain('bio: |-')
        ->and($driver->parse($yaml))->toBe(['bio' => "One.\nTwo.", 'skills' => ['php']]);
});

it('ends the file with a newline when the last value is a literal block', function (): void {
    $driver = new YamlDriver;

    expect($driver->serialize(['title' => 'T', 'bio' => "One.\nTwo."]))->toEndWith("Two.\n");
});

it('treats an empty document as a record with no fields', function (): void {
    $driver = new YamlDriver;

    expect($driver->parse($driver->serialize(['slug' => 'alex'])))->toBe([]);
});

it('throws a Paper exception when the yaml is malformed', function (): void {
    $driver = new YamlDriver;
    $driver->parse("name: [unclosed\nrole: Engineer");
})->throws(FileParseException::class, 'Failed to parse YAML');

it('throws when the yaml root is not a mapping', function (): void {
    $driver = new YamlDriver;
    $driver->parse('just a string');
})->throws(FileParseException::class, 'Root must be a mapping');
