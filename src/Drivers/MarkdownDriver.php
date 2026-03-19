<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use Spatie\YamlFrontMatter\YamlFrontMatter;
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

    /**
     * @return array<string, mixed>
     */
    public function parse(string $filepath): array
    {
        $content = @file_get_contents($filepath);

        if ($content === false) {
            throw FileParseException::unreadable($filepath);
        }

        $document = YamlFrontMatter::parse($content);

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

        $yaml = Yaml::dump($data);

        return "---\n$yaml---\n\n$content\n";
    }
}
