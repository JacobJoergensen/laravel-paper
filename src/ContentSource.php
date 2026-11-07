<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;

/**
 * @internal
 */
final readonly class ContentSource
{
    public function __construct(
        public StorageAdapterContract $adapter,
        public DriverContract $driver,
        public string $path,
        public bool $nested,
    ) {}
}
