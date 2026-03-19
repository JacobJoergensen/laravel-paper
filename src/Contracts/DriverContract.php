<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

interface DriverContract
{
    /**
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * @return array<string, mixed>
     */
    public function parse(string $filepath): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function serialize(array $data): string;
}
