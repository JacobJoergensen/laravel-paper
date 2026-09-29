<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class MarkdownDriver implements DriverContract
{
    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private const string FRONTMATTER = '/\A\s*---\h*\R(?<matter>.*?)^---\h*$\R?/ms';

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['md', 'markdown'];
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

        $content = str_starts_with($content, self::BYTE_ORDER_MARK)
            ? substr($content, strlen(self::BYTE_ORDER_MARK))
            : $content;

        if (preg_match(self::FRONTMATTER, $content, $match) !== 1) {
            return ['content' => rtrim(ltrim($content, "\r\n"))];
        }

        try {
            $matter = Yaml::parse($match['matter']);
        } catch (ParseException $e) {
            throw FileParseException::invalidFrontmatter($filepath, $e->getMessage());
        }

        $body = substr($content, strlen($match[0]));

        /** @var array<string, mixed> $data */
        $data = is_array($matter) ? $matter : [];
        $data['content'] = rtrim(ltrim($body, "\r\n"));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function serialize(array $data): string
    {
        $content = isset($data['content']) && is_string($data['content']) ? $data['content'] : '';
        unset($data['content'], $data['slug']);

        if ($data === []) {
            $readsAsFrontmatter = preg_match(self::FRONTMATTER, $content) === 1;

            return $readsAsFrontmatter ? "---\n---\n\n$content\n" : "$content\n";
        }

        try {
            $flags = Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE;
            $yaml = Yaml::dump($data, PHP_INT_MAX, 4, $flags);
        } catch (DumpException $e) {
            throw FileSerializeException::invalidYaml($e->getMessage());
        }

        return "---\n$yaml---\n\n$content\n";
    }
}
