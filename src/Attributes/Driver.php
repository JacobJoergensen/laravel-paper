<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Driver
{
    public function __construct(
        public string $name,
    ) {}
}
