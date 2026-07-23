<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Console;

use Illuminate\Console\Command;

final class RefreshCommand extends Command
{
    protected $signature = 'paper:refresh {model* : One or more Paper model classes}';

    protected $description = 'Clear and rebuild the manifest for the given Paper models';

    public function handle(): int
    {
        $models = $this->argument('model');

        $cleared = $this->callSilently('paper:clear', ['model' => $models]);
        $cached = $this->call('paper:cache', ['model' => $models]);

        return $cleared === self::SUCCESS && $cached === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
