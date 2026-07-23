<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

/**
 * @phpstan-require-extends Model
 */
interface PaperModel
{
    /**
     * @return PaperQueryBuilder<static&Model>
     */
    public static function query(): PaperQueryBuilder;

    public function getContentPath(): string;
}
