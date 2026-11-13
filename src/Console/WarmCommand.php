<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'paper:warm')]
final class WarmCommand extends PaperCommand
{
    protected $signature = 'paper:warm {model?* : Paper model classes, defaults to every model in app/Models}';

    protected $description = 'Build the manifest ahead of the first request';

    protected function runFor(string $model): void
    {
        $source = PaperQueryBuilder::sourceFor($model);
        $records = app(PaperManifest::class)->reconcile($source);

        $this->info(sprintf('%s: warmed %d records.', $model, count($records)));
    }
}
