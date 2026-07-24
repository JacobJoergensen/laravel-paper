<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('markdown')]
#[ContentPath('blog')]
final class CountingModel extends Model implements PaperModel
{
    use Paper;

    public static int $hydrations = 0;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        self::$hydrations++;
        parent::__construct($attributes);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rank' => 'integer'];
    }
}
