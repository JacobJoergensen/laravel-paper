<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;

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

        $data = new YamlDriver()->parse($match['matter']);
        $body = substr($contents, strlen($match[0]));

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

        $yaml = new YamlDriver()->serialize($data);

        return "---\n$yaml---\n\n$content\n";
    }
}
