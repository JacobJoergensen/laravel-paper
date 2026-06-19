<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Console\CacheCommand;
use JacobJoergensen\LaravelPaper\Console\ClearCommand;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;

final class PaperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaperManifest::class, function (Application $app): PaperManifest {
            return new PaperManifest($app->make(Repository::class));
        });

        $this->app->singleton(MarkdownDriver::class);
        $this->app->singleton(JsonDriver::class);

        $this->app->singleton(DriverRegistry::class, function (): DriverRegistry {
            $registry = new DriverRegistry;
            $registry->register('markdown', MarkdownDriver::class);
            $registry->register('json', JsonDriver::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CacheCommand::class,
                ClearCommand::class,
            ]);
        }
    }
}
