<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

final class ClearCommand extends PaperCommand
{
    protected $signature = 'paper:clear {model?* : Paper model classes, defaults to every model in app/Models}';

    protected $description = 'Clear the manifest so the next query rebuilds it from the files';

    public function __construct(private readonly PaperManifest $manifest)
    {
        parent::__construct();
    }

    protected function runFor(string $model): void
    {
        $resolved = PaperQueryBuilder::resolveFor($model);
        $path = PaperQueryBuilder::contentPathFor($model);

        $this->manifest->flush($resolved['adapter'], $path);

        $this->info(sprintf('%s: manifest cleared.', $model));
    }
}
