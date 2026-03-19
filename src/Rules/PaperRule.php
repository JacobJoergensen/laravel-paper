<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Rules;

use Illuminate\Database\Eloquent\Model;

final class PaperRule
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function exists(string $model, string $column = 'slug'): PaperExistsRule
    {
        return new PaperExistsRule($model, $column);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function unique(string $model, string $column = 'slug'): PaperUniqueRule
    {
        return new PaperUniqueRule($model, $column);
    }
}
