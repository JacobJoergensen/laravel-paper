<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'paper:warm')]
final class WarmCommand extends PaperCommand
{
    protected $signature = 'paper:warm
        {model?* : Paper model classes, defaults to every model in app/Models}
        {--compile : Also write the manifest to a PHP file that opcache serves, for servers that only read content}';

    protected $description = 'Build the manifest ahead of the first request';

    protected function runFor(string $model): void
    {
        $source = PaperQueryBuilder::sourceFor($model);
        $manifest = app(PaperManifest::class);

        $records = $this->option('compile') === true
            ? $manifest->compile($source)
            : $manifest->reconcile($source);

        $this->info(sprintf('%s: warmed %d records.', $model, count($records)));
    }
}
