<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

interface DriverContract
{
    /**
     * @return list<string>
     */
    public function extensions(): array;

    public function bodyColumn(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function parse(string $contents): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function serialize(array $data): string;
}
