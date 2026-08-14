<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Benchmarks;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('markdown')]
#[ContentPath('benchmarks/.fixtures/posts')]
final class BenchmarkPost extends PaperModel
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tags' => 'array'];
    }
}
