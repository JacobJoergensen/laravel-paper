<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class CacheCommand extends Command
{
    protected $signature = 'paper:cache {model* : One or more Paper model classes}';

    protected $description = 'Warm the manifest for the given Paper models';

    public function handle(PaperManifest $manifest): int
    {
        $models = $this->argument('model');

        if (! is_array($models)) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($models as $model) {
            if (! is_string($model) || ! $this->isPaperModel($model)) {
                $this->error(sprintf('%s is not a Paper model.', is_string($model) ? $model : 'argument'));
                $failed = true;

                continue;
            }

            $resolved = PaperQueryBuilder::resolveFor($model);
            $path = PaperQueryBuilder::contentPathFor($model);

            $manifest->flush($resolved['adapter'], $path);
            $records = $manifest->records($resolved['adapter'], $resolved['driver'], $path, $resolved['nested']);

            $this->info(sprintf('%s: cached %d records.', $model, count($records)));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @phpstan-assert-if-true class-string<Model> $model
     */
    private function isPaperModel(string $model): bool
    {
        return class_exists($model)
            && is_subclass_of($model, Model::class)
            && in_array(Paper::class, class_uses_recursive($model), true);
    }
}
