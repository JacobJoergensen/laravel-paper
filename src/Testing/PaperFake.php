<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Testing;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\PaperCasts;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class PaperFake
{
    public const int BASE_MTIME = 1_700_000_000;

    /**
     * @param  class-string<Model&PaperModel>  $modelClass
     * @param  array<string, array<string, mixed>>  $content
     */
    public static function fake(string $modelClass, array $content = []): FakeAdapter
    {
        $source = PaperQueryBuilder::sourceFor($modelClass);
        $driver = $source->driver;
        $extension = $driver->extensions()[0];

        $adapter = new FakeAdapter;
        $mtime = self::BASE_MTIME;

        foreach ($content as $slug => $attributes) {
            $slug = (string) $slug;

            PaperQueryBuilder::guardWritableSlug($slug, $source->nested);

            $model = new $modelClass;
            $model->forceFill($attributes);

            $stored = PaperCasts::toStorage($model, $model->getAttributes());
            $path = $source->path.'/'.$slug.'.'.$extension;

            $adapter->seed($path, $driver->serialize($stored), $mtime++);
        }

        PaperQueryBuilder::fake($modelClass, $adapter);

        return $adapter;
    }

    public static function reset(): void
    {
        PaperQueryBuilder::forgetFakes();
    }
}
