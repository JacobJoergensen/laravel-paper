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

    public function bodyColumn(): string
    {
        return 'content';
    }

    public function bodySyntax(): string
    {
        return 'markdown';
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $contents): array
    {
        $contents = str_starts_with($contents, self::BYTE_ORDER_MARK)
            ? substr($contents, strlen(self::BYTE_ORDER_MARK))
            : $contents;

        if (preg_match(self::FRONTMATTER, $contents, $match) !== 1) {
            return ['content' => rtrim(ltrim($contents, "\r\n"))];
        }

        try {
            $matter = Yaml::parse($match['matter']);
        } catch (ParseException $e) {
            throw FileParseException::invalidFrontmatter($e->getMessage());
        }

        $body = substr($contents, strlen($match[0]));

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
