<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use JacobJoergensen\LaravelPaper\PaperServiceProvider;
use JacobJoergensen\LaravelPaper\Testing\RefreshesPaperFakes;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshesPaperFakes;

    private static ?string $basePath = null;

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PaperServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        if (self::$basePath === null) {
            $files = new Filesystem;
            $root = sys_get_temp_dir().'/paper-tests-'.getmypid().'-'.uniqid();

            if (! $files->copyDirectory(__DIR__.'/content', $root.'/tests/content')) {
                throw new RuntimeException("Could not copy the test content to {$root}.");
            }

            $files->ensureDirectoryExists($root.'/bootstrap/cache');
            $files->ensureDirectoryExists($root.'/storage/framework/cache');

            register_shutdown_function(fn (): bool => $files->deleteDirectory($root));

            self::$basePath = $root;
        }

        $app->setBasePath(self::$basePath);
    }
}
