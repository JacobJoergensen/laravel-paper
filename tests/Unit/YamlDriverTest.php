<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\YamlDriver;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;

it('reads .yaml and .yml files', function (): void {
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

it('reports no body column and no syntax for a data-only format', function (): void {
    $driver = new YamlDriver;

    expect($driver->bodyColumn())->toBeNull()
        ->and($driver->bodySyntax())->toBeNull();
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

it('reads an unquoted date as the date it states and writes it back as that date', function (): void {
    $driver = new YamlDriver;

    $data = $driver->parse("day: 2024-01-15\nmoment: 2024-01-15 10:30:00\nzoned: 2024-01-15T10:30:00+02:00\n");

    expect($data)->toBe([
        'day' => '2024-01-15',
        'moment' => '2024-01-15 10:30:00',
        'zoned' => '2024-01-15 10:30:00+02:00',
    ])->and($driver->parse($driver->serialize($data)))->toBe($data);
});

it('writes an empty list as a list', function (): void {
    $driver = new YamlDriver;

    expect($driver->serialize(['tags' => []]))->toBe("tags: []\n");
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

it('throws when the yaml root is not a mapping', function (string $yaml): void {
    $driver = new YamlDriver;
    $driver->parse($yaml);
})->throws(FileParseException::class, 'Root must be a mapping')->with([
    'string' => 'just a string',
    'list' => "- one\n- two\n",
]);
