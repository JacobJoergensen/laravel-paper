<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use DateTimeInterface;
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
            $data = self::decode($content);
        } catch (ParseException $e) {
            throw FileParseException::invalidYaml($filepath, $e->getMessage());
        }

        if ($data === null) {
            return [];
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw FileParseException::invalidYaml($filepath, 'Root must be a mapping');
        }

        /** @var array<string, mixed> */
        return $data;
    }

    /**
     * @internal
     *
     * @throws ParseException
     */
    public static function decode(string $yaml): mixed
    {
        $data = Yaml::parse($yaml, Yaml::PARSE_DATETIME);

        if (! is_array($data)) {
            return $data;
        }

        array_walk_recursive($data, function (mixed &$value): void {
            if ($value instanceof DateTimeInterface) {
                $format = match (true) {
                    $value->format('H:i:s') === '00:00:00' && $value->getOffset() === 0 => 'Y-m-d',
                    $value->getOffset() === 0 => 'Y-m-d H:i:s',
                    default => 'Y-m-d H:i:sP',
                };

                $value = $value->format($format);
            }
        });

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
            $flags = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
                | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
                | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE;
            $yaml = Yaml::dump($data, PHP_INT_MAX, 4, $flags);
        } catch (DumpException $e) {
            throw FileSerializeException::invalidYaml($e->getMessage());
        }

        // Symfony omits the final newline when the last value is a stripped literal block.
        return str_ends_with($yaml, "\n") ? $yaml : $yaml."\n";
    }
}
