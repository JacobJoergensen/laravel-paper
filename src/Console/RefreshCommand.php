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
        $resolved = PaperQueryBuilder::resolveFor($model);
        $path = PaperQueryBuilder::contentPathFor($model);
        $manifest = app(PaperManifest::class);

        $manifest->flush($resolved['adapter'], $resolved['driver'], $path, $resolved['nested']);

        $records = $manifest->reconcile($resolved['adapter'], $resolved['driver'], $path, $resolved['nested']);

        $this->info(sprintf('%s: refreshed %d records.', $model, count($records)));
    }
}
