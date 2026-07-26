<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class RefreshCommand extends PaperCommand
{
    protected $signature = 'paper:refresh {model?* : Paper model classes, defaults to every model in app/Models}';

    protected $description = 'Rebuild the manifest from scratch';

    public function __construct(private readonly PaperManifest $manifest)
    {
        parent::__construct();
    }

    protected function runFor(string $model): void
    {
        $resolved = PaperQueryBuilder::resolveFor($model);
        $path = PaperQueryBuilder::contentPathFor($model);

        $this->manifest->flush($resolved['adapter'], $path);

        $records = $this->manifest->reconcile($resolved['adapter'], $resolved['driver'], $path, $resolved['nested']);

        $this->info(sprintf('%s: refreshed %d records.', $model, count($records)));
    }
}
