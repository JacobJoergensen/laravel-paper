<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

final readonly class ConditionalWriteResult
{
    private function __construct(
        public ConditionalWriteStatus $status,
        public ?string $version = null,
        public ?string $path = null,
    ) {}

    public static function written(string $version): self
    {
        return new self(ConditionalWriteStatus::Written, $version);
    }

    public static function removed(): self
    {
        return new self(ConditionalWriteStatus::Removed);
    }

    public static function mismatch(): self
    {
        return new self(ConditionalWriteStatus::Mismatch);
    }

    public static function missing(): self
    {
        return new self(ConditionalWriteStatus::Missing);
    }

    public static function taken(string $path): self
    {
        return new self(ConditionalWriteStatus::Taken, path: $path);
    }

    public static function failed(): self
    {
        return new self(ConditionalWriteStatus::Failed);
    }
}
