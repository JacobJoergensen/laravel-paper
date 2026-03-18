<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ContentPath
{
    public function __construct(
        public string $path,
    ) {}
}
