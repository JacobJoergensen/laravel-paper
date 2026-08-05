<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class MarkdownDriver implements DriverContract
{
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

    /**
     * @return array<string, mixed>
     */
    public function parse(string $contents): array
    {
        try {
            $document = YamlFrontMatter::parse($contents);
        } catch (ParseException $e) {
            throw FileParseException::invalidFrontmatter($e->getMessage());
        }

        /** @var array<string, mixed> $data */
        $data = $document->matter();
        $data['content'] = trim($document->body());

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
            return "$content\n";
        }

        $yaml = Yaml::dump($data, PHP_INT_MAX);

        return "---\n$yaml---\n\n$content\n";
    }
}
