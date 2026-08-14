<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel as PaperModelContract;

/**
 * @phpstan-consistent-constructor
 */
abstract class PaperModel extends Model implements PaperModelContract
{
    use Paper;
}
