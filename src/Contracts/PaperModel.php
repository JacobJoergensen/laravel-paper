<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Contracts;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Relations\PaperRelation;

/**
 * @phpstan-require-extends Model
 */
interface PaperModel
{
    /**
     * @return PaperQueryBuilder<static&Model>
     */
    public static function query(): PaperQueryBuilder;

    /**
     * @return array<string, PaperRelation<Model&PaperModel>>
     */
    public function paperRelations(): array;

    public function getContentPath(): string;

    public function getFilePath(): string;
}
