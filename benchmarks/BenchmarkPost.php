<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Benchmarks;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('markdown')]
#[ContentPath('benchmarks/.fixtures/posts')]
final class BenchmarkPost extends Model
{
    use Paper;

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
