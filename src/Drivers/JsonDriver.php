<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;

final readonly class JsonDriver implements DriverContract
{
    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['json'];
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $contents): array
    {
        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw FileParseException::invalidJson(json_last_error_msg());
        }

        if (! is_array($data)) {
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

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    }
}
