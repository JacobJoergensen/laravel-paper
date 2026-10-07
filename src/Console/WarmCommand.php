<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class WarmCommand extends PaperCommand
{
    protected $signature = 'paper:warm
        {model?* : Paper model classes, defaults to every model in app/Models}
        {--compile : Also write the manifest to a PHP file that opcache serves, for servers that only read content}';

    protected $description = 'Build the manifest ahead of the first request';

    public function __construct(private readonly PaperManifest $manifest)
    {
        parent::__construct();
    }

    protected function runFor(string $model): void
    {
        $resolved = PaperQueryBuilder::resolveFor($model);
        $path = PaperQueryBuilder::contentPathFor($model);

        $records = $this->option('compile') === true
            ? $this->manifest->compile($resolved['adapter'], $resolved['driver'], $path, $resolved['nested'])
            : $this->manifest->reconcile($resolved['adapter'], $resolved['driver'], $path, $resolved['nested']);

        $this->info(sprintf('%s: warmed %d records.', $model, count($records)));
    }
}
