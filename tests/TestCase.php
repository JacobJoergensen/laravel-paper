<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app): void
    {
        $app->setBasePath(dirname(__DIR__));
    }
}
