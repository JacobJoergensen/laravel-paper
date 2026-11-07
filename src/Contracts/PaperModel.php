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
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromRecord(array $attributes, string $version, string $extension): static;

    /**
     * @return array<string, PaperRelation<Model&PaperModel>>
     */
    public function paperRelations(): array;

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): false|int;

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): false|int;

    public function getContentPath(): string;

    public function getFilePath(): string;
}
