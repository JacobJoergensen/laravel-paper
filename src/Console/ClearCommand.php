<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'paper:clear')]
final class ClearCommand extends PaperCommand
{
    protected $signature = 'paper:clear {model?* : Paper model classes, defaults to every model in app/Models}';

    protected $description = 'Clear the manifest so the next query rebuilds it from the files';

    protected function runFor(string $model): void
    {
        $resolved = PaperQueryBuilder::resolveFor($model);
        $path = PaperQueryBuilder::contentPathFor($model);

        app(PaperManifest::class)->flush($resolved['adapter'], $resolved['driver'], $path, $resolved['nested']);

        $this->info(sprintf('%s: manifest cleared.', $model));
    }
}
