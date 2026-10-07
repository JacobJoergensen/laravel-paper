<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Composer\InstalledVersions;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Console\ClearCommand;
use JacobJoergensen\LaravelPaper\Console\RefreshCommand;
use JacobJoergensen\LaravelPaper\Console\ValidateCommand;
use JacobJoergensen\LaravelPaper\Console\WarmCommand;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\Drivers\YamlDriver;

final class PaperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/paper.php', 'paper');

        $this->app->scoped(PaperManifest::class, function (Application $app): PaperManifest {
            $config = $app->make(Config::class);
            $store = $config->get('paper.cache_store');
            $cache = $app->make(CacheFactory::class)->store(is_string($store) ? $store : null);

            $watch = $config->get('paper.watch');
            $watching = $watch === 'auto' ? $app->environment('local') === true : $watch === true;

            $package = 'jacobjoergensen/laravel-paper';
            $version = InstalledVersions::getReference($package) ?? InstalledVersions::getVersion($package) ?? '';

            $opcacheSetting = in_array(PHP_SAPI, ['cli', 'phpdbg'], true) ? 'opcache.enable_cli' : 'opcache.enable';
            $opcache = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
                && filter_var(ini_get($opcacheSetting), FILTER_VALIDATE_BOOL);

            return new PaperManifest(
                $cache,
                $config->integer('paper.lock_ttl'),
                $config->integer('paper.lock_wait'),
                $watching,
                $version,
                $app->bootstrapPath('cache'),
                $opcache,
            );
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
            $this->publishes([
                __DIR__.'/../config/paper.php' => config_path('paper.php'),
            ], 'paper-config');

            $this->commands([
                ClearCommand::class,
                RefreshCommand::class,
                ValidateCommand::class,
                WarmCommand::class,
            ]);
        }
    }
}
