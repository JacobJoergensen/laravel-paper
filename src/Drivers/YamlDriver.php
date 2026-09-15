<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class YamlDriver implements DriverContract
{
    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['yaml', 'yml'];
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $filepath): array
    {
        $content = @file_get_contents($filepath);

        if ($content === false) {
            throw FileParseException::unreadable($filepath);
        }

        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            throw FileParseException::invalidYaml($filepath, $e->getMessage());
        }

        if ($data === null) {
            return [];
        }

        if (! is_array($data)) {
            throw FileParseException::invalidYaml($filepath, 'Root must be a mapping');
        }

        /** @var array<string, mixed> */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function serialize(array $data): string
    {
        unset($data['slug']);

        if ($data === []) {
            return "\n";
        }

        try {
            $yaml = Yaml::dump($data, PHP_INT_MAX, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
        } catch (DumpException $e) {
            throw FileSerializeException::invalidYaml($e->getMessage());
        }

        // Symfony omits the final newline when the last value is a stripped literal block.
        return str_ends_with($yaml, "\n") ? $yaml : $yaml."\n";
    }
}
