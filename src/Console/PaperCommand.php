<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Exceptions\PaperException;
use JacobJoergensen\LaravelPaper\Paper;
use ReflectionClass;

/**
 * @internal
 */
abstract class PaperCommand extends Command
{
    public function handle(): int
    {
        $models = $this->models();

        if ($models === []) {
            return self::INVALID;
        }

        $status = self::SUCCESS;

        foreach ($models as $model) {
            try {
                $this->runFor($model);
            } catch (PaperException $e) {
                $this->error(sprintf('%s: %s', $model, $e->getMessage()));
                $status = self::FAILURE;
            }
        }

        return $status;
    }

    /**
     * @param  class-string<Model&PaperModel>  $model
     */
    abstract protected function runFor(string $model): void;

    /**
     * @return list<class-string<Model&PaperModel>>
     */
    private function models(): array
    {
        /** @var list<string> $named */
        $named = $this->argument('model');

        if ($named !== []) {
            return $this->named($named);
        }

        $discovered = $this->discover();

        if ($discovered === []) {
            $this->error('No Paper models found in app/Models.');
        }

        return $discovered;
    }

    /**
     * @param  list<string>  $named
     * @return list<class-string<Model&PaperModel>>
     */
    private function named(array $named): array
    {
        $models = [];

        foreach ($named as $model) {
            if (! class_exists($model)) {
                $this->error(sprintf('%s does not exist.', $model));

                return [];
            }

            if (! $this->isPaperModel($model)) {
                $this->error(sprintf('%s is not a Paper model.', $model));

                return [];
            }

            $models[] = $model;
        }

        return $models;
    }

    /**
     * @return list<class-string<Model&PaperModel>>
     */
    private function discover(): array
    {
        $files = app(Filesystem::class);
        $directory = app_path('Models');

        if (! $files->isDirectory($directory)) {
            return [];
        }

        $models = [];

        foreach ($files->allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromFile($files, $file->getPathname());

            if ($class !== null && $this->isPaperModel($class) && new ReflectionClass($class)->isInstantiable()) {
                $models[] = $class;
            }
        }

        return $models;
    }

    private function classFromFile(Filesystem $files, string $path): ?string
    {
        $contents = $files->get($path);

        if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1].'\\'.pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * @phpstan-assert-if-true class-string<Model&PaperModel> $model
     */
    private function isPaperModel(string $model): bool
    {
        return class_exists($model) && isset(class_uses_recursive($model)[Paper::class]);
    }
}
