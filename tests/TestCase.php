<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests;

use Illuminate\Foundation\Application;
use JacobJoergensen\LaravelPaper\PaperServiceProvider;
use JacobJoergensen\LaravelPaper\Testing\RefreshesPaperFakes;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesPaperFakes;

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
        $app->setBasePath(dirname(__DIR__));
    }
}
