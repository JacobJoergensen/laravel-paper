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
    public function parse(string $filepath): array
    {
        $content = @file_get_contents($filepath);

        if ($content === false) {
            throw FileParseException::unreadable($filepath);
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw FileParseException::invalidJson($filepath, json_last_error_msg());
        }

        if (! is_array($data)) {
            throw FileParseException::invalidJson($filepath, 'Root must be an object');
        }

        /** @var array<string, mixed> */
        return $data;
    }
}
