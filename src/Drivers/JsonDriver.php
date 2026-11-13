<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\FileSerializeException;

final readonly class JsonDriver implements DriverContract
{
    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['json'];
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

        if (trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw FileParseException::invalidJson(json_last_error_msg());
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw FileParseException::invalidJson('Root must be an object');
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
            return "{}\n";
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw FileSerializeException::invalidJson(json_last_error_msg());
        }

        return $json."\n";
    }
}
