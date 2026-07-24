<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class ValidateCommand extends Command
{
    protected $signature = 'paper:validate {model* : One or more Paper model classes}';

    protected $description = 'Check every content file parses and hydrates; lenient casts like integer coerce silently and are not type-checked';

    public function handle(): int
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

            $failures = PaperQueryBuilder::forModel($model)->validateFiles();

            foreach ($failures as $failure) {
                $this->error(sprintf('%s: %s', $failure['path'], $failure['error']));
            }

            if ($failures === []) {
                $this->info(sprintf('%s: all files valid.', $model));

                continue;
            }

            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @phpstan-assert-if-true class-string<Model&PaperModel> $model
     */
    private function isPaperModel(string $model): bool
    {
        return is_subclass_of($model, PaperModel::class);
    }
}
