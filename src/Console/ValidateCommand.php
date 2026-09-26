<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use JacobJoergensen\LaravelPaper\Exceptions\PaperException;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'paper:validate')]
final class ValidateCommand extends Command
{
    protected $signature = 'paper:validate
                            {model?* : Paper model classes, defaults to every model in app/Models}
                            {--json : Print the failures as JSON instead of console output}';

    protected $description = 'Check that every content file is read, parses, and hydrates';

    /** @var list<array{model: string, path: string|null, error: string}> */
    private array $failures = [];

    public function handle(): int
    {
        $models = $this->models();

        if ($models === []) {
            return self::INVALID;
        }

        foreach ($models as $model) {
            $this->validateModel($model);
        }

        if ($this->json()) {
            $this->line(json_encode($this->failures, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function validateModel(string $model): void
    {
        try {
            /** @var PaperQueryBuilder<Model> $query */
            $query = $model::query();
            $result = $query->validateFiles();
        } catch (PaperException $e) {
            $this->record($model, null, $e->getMessage());

            return;
        }

        foreach ($result['failures'] as $failure) {
            $this->record($model, $failure['path'], $failure['error']);
        }

        if ($result['failures'] !== [] || $this->json()) {
            return;
        }

        // Reporting the count keeps a content path that matched nothing from reading as a pass.
        $this->components->twoColumnDetail($model, $result['checked'] === 0
            ? 'no content files found'
            : sprintf('%d files valid', $result['checked']));
    }

    private function record(string $model, ?string $path, string $error): void
    {
        $this->failures[] = ['model' => $model, 'path' => $path, 'error' => $error];

        if (! $this->json()) {
            $this->components->error(sprintf('%s: %s', $path ?? $model, $error));
        }
    }

    private function json(): bool
    {
        return $this->option('json') === true;
    }

    /**
     * @return list<class-string<Model>>
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
            $this->components->error('No Paper models found in app/Models.');
        }

        return $discovered;
    }

    /**
     * @param  list<string>  $named
     * @return list<class-string<Model>>
     */
    private function named(array $named): array
    {
        $models = [];

        foreach ($named as $model) {
            if (! class_exists($model)) {
                $this->components->error(sprintf('[%s] does not exist.', $model));

                return [];
            }

            if (! $this->isPaperModel($model)) {
                $this->components->error(sprintf('[%s] is not a Paper model.', $model));

                return [];
            }

            $models[] = $model;
        }

        return $models;
    }

    /**
     * @return list<class-string<Model>>
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
     * @phpstan-assert-if-true class-string<Model> $model
     */
    private function isPaperModel(string $model): bool
    {
        return class_exists($model) && isset(class_uses_recursive($model)[Paper::class]);
    }
}
