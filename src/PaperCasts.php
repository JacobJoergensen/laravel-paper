<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
final class PaperCasts
{
    private const array JSON_CASTS = ['array', 'json', 'object', 'collection'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function toStorage(Model $model, array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($value) && $model->hasCast($key, self::JSON_CASTS)) {
                $attributes[$key] = json_decode($value, true);
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function fromStorage(Model $model, array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_array($value) && $model->hasCast($key, self::JSON_CASTS)) {
                $attributes[$key] = json_encode($value);
            }
        }

        return $attributes;
    }
}
