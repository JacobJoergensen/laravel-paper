<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'paper:refresh')]
final class RefreshCommand extends PaperCommand
{
    protected $signature = 'paper:refresh {model?* : Paper model classes, defaults to every model in app/Models}';

    protected $description = 'Rebuild the manifest from scratch';

    protected function runFor(string $model): void
    {
        $source = PaperQueryBuilder::sourceFor($model);
        $manifest = app(PaperManifest::class);

        $manifest->flush($source);

        $records = $manifest->reconcile($source);

        $this->info(sprintf('%s: refreshed %d records.', $model, count($records)));
    }
}
