<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Exceptions\PaperException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class ValidateCommand extends PaperCommand
{
    protected $signature = 'paper:validate
                            {model?* : Paper model classes, defaults to every model in app/Models}
                            {--json : Print the failures as JSON instead of console output}';

    protected $description = 'Check that every content file is read, parses, and hydrates';

    /** @var list<array{model: string, path: string|null, error: string}> */
    private array $failures = [];

    public function handle(): int
    {
        $status = parent::handle();

        if ($status === self::INVALID) {
            return $status;
        }

        if ($this->json()) {
            $this->line(json_encode($this->failures, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return $this->failures === [] ? $status : self::FAILURE;
    }

    protected function runFor(string $model): void
    {
        try {
            $result = PaperQueryBuilder::forModel($model)->validateFiles();
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
        $this->info($result['checked'] === 0
            ? sprintf('%s: no content files found.', $model)
            : sprintf('%s: %d files valid.', $model, $result['checked']));
    }

    private function record(string $model, ?string $path, string $error): void
    {
        $this->failures[] = ['model' => $model, 'path' => $path, 'error' => $error];

        if (! $this->json()) {
            $this->error(sprintf('%s: %s', $path ?? $model, $error));
        }
    }

    private function json(): bool
    {
        return $this->option('json') === true;
    }
}
