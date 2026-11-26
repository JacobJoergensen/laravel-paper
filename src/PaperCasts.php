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
    private const array OBJECT_CASTS = [
        AsArrayObject::class,
        AsCollection::class,
        AsFluent::class,
    ];

    private const array JSON_CASTS = [
        'array',
        'json',
        'object',
        'collection',
        AsEnumArrayObject::class,
        AsEnumCollection::class,
        ...self::OBJECT_CASTS,
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function toStorage(Model $model, array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $castName = self::castName($model, $key);

            if (! in_array($castName, self::JSON_CASTS, true)) {
                continue;
            }

            if (in_array($castName, self::OBJECT_CASTS, true)) {
                $castValue = $model->getAttribute($key);
                $value = json_encode($castValue, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $attributes[$key] = $decoded;
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
            if (! is_array($value)) {
                continue;
            }

            $castName = self::castName($model, $key);

            if (in_array($castName, self::JSON_CASTS, true)) {
                $attributes[$key] = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            }
        }

        return $attributes;
    }

    private static function castName(Model $model, string $key): ?string
    {
        $cast = $model->getCasts()[$key] ?? null;

        if (! is_string($cast)) {
            return null;
        }

        return explode(':', $cast, 2)[0];
    }
}
