<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Rules;

use JacobJoergensen\LaravelPaper\Contracts\PaperModel;

final class PaperRule
{
    /**
     * @param  class-string<PaperModel>  $model
     */
    public static function exists(string $model, string $column = 'slug'): PaperExistsRule
    {
        return new PaperExistsRule($model, $column);
    }

    /**
     * @param  class-string<PaperModel>  $model
     */
    public static function unique(string $model, string $column = 'slug'): PaperUniqueRule
    {
        return new PaperUniqueRule($model, $column);
    }
}
