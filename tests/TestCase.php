<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests;

use JacobJoergensen\LaravelPaper\PaperServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PaperServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->setBasePath(dirname(__DIR__));
    }
}
