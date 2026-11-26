<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsFluent;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
final class PaperCasts
{
    private const array JSON_CASTS = ['array', 'json', 'json:unicode', 'object', 'collection'];

    private const array JSON_CAST_CLASSES = [
        AsArrayObject::class,
        AsCollection::class,
        AsEnumArrayObject::class,
        AsEnumCollection::class,
        AsFluent::class,
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function toStorage(Model $model, array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($value) && self::isJsonCast($model, $key)) {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $attributes[$key] = $decoded;
                }
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
            if (is_array($value) && self::isJsonCast($model, $key)) {
                $attributes[$key] = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            }
        }

        return $attributes;
    }

    private static function isJsonCast(Model $model, int|string $key): bool
    {
        $attribute = (string) $key;

        if ($model->hasCast($attribute, self::JSON_CASTS)) {
            return true;
        }

        $cast = $model->getCasts()[$attribute] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        $castClass = explode(':', $cast, 2)[0];

        return in_array($castClass, self::JSON_CAST_CLASSES, true);
    }
}
