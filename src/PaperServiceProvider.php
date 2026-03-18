<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use JacobJoergensen\LaravelPaper\Cache\FileModificationCache;
use JacobJoergensen\LaravelPaper\Contracts\CacheContract;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;

final class PaperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CacheContract::class, function (Application $app): CacheContract {
            return new FileModificationCache($app->make(Repository::class));
        });

        $this->app->singleton(MarkdownDriver::class);
        $this->app->singleton(JsonDriver::class);

        $this->app->singleton('paper.drivers', fn (): array => [
            'markdown' => MarkdownDriver::class,
            'json' => JsonDriver::class,
        ]);
    }
}
