<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Testing;

use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class PaperFake
{
    /**
     * Fixed base so faked updated_at values are reproducible across runs, never time().
     */
    public const int BASE_MTIME = 1_700_000_000;

    /**
     * @param  class-string<PaperModel>  $modelClass
     * @param  array<string, array<string, mixed>>  $content  Slug to attributes.
     */
    public static function fake(string $modelClass, array $content = []): FakeAdapter
    {
        $resolved = PaperQueryBuilder::resolveFor($modelClass);
        $driver = $resolved['driver'];
        $contentPath = PaperQueryBuilder::contentPathFor($modelClass);
        $extension = $driver->extensions()[0];

        $adapter = new FakeAdapter;
        $index = 0;

        foreach ($content as $slug => $attributes) {
            $path = $contentPath.'/'.$slug.'.'.$extension;
            $adapter->seed($path, $driver->serialize($attributes), self::BASE_MTIME + $index);
            $index++;
        }

        PaperQueryBuilder::fake($modelClass, $adapter);

        return $adapter;
    }

    public static function reset(): void
    {
        PaperQueryBuilder::forgetFakes();
    }
}
