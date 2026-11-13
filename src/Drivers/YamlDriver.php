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
    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['yaml', 'yml'];
    }

    public function bodyColumn(): ?string
    {
        return null;
    }

    public function bodySyntax(): ?string
    {
        return null;
    }

    public function body(string $contents): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $contents): array
    {
        $contents = str_starts_with($contents, self::BYTE_ORDER_MARK)
            ? substr($contents, strlen(self::BYTE_ORDER_MARK))
            : $contents;

        try {
            $data = Yaml::parse($contents, Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw FileParseException::invalidYaml($e->getMessage());
        }

        if ($data === null) {
            return [];
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw FileParseException::invalidYaml('Root must be a mapping');
        }

        array_walk_recursive($data, function (mixed &$value): void {
            if ($value instanceof DateTimeInterface) {
                $seconds = $value->format('u') === '000000' ? 's' : 's.u';

                $format = match (true) {
                    $value->format('H:i:s.u') === '00:00:00.000000' && $value->getOffset() === 0 => 'Y-m-d',
                    $value->getOffset() === 0 => "Y-m-d H:i:$seconds",
                    default => "Y-m-d H:i:{$seconds}P",
                };

                $value = $value->format($format);
            }
        });

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
            $flags = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
                | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
                | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE;
            $yaml = Yaml::dump($data, PHP_INT_MAX, 4, $flags);
        } catch (DumpException $e) {
            throw FileSerializeException::invalidYaml($e->getMessage());
        }

        return str_ends_with($yaml, "\n") ? $yaml : $yaml."\n";
    }
}
