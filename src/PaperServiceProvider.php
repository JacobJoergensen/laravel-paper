<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use JacobJoergensen\LaravelPaper\Cache\FileModificationCache;
use JacobJoergensen\LaravelPaper\Console\ValidateCommand;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Drivers\YamlDriver;

final class PaperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CacheContract::class, function (Application $app): CacheContract {
            return new FileModificationCache($app->make(Repository::class));
        });

        $this->app->singleton(MarkdownDriver::class);
        $this->app->singleton(JsonDriver::class);
        $this->app->singleton(YamlDriver::class);

        $this->app->singleton(DriverRegistry::class, function (): DriverRegistry {
            $registry = new DriverRegistry;
            $registry->register('markdown', MarkdownDriver::class);
            $registry->register('json', JsonDriver::class);
            $registry->register('yaml', YamlDriver::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ValidateCommand::class,
            ]);
        }
    }
}
