<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use JacobJoergensen\LaravelPaper\PaperServiceProvider;
use Orchestra\Testbench\Foundation\Application as Testbench;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/BenchmarkPost.php';

$bootstrapCache = dirname(__DIR__).'/bootstrap/cache';

if (! is_dir($bootstrapCache)) {
    mkdir($bootstrapCache, 0777, true);
}

/** @var Application $app */
$app = Testbench::create(
    basePath: dirname(__DIR__),
    options: ['extra' => ['providers' => [PaperServiceProvider::class], 'dont-discover' => ['*']]],
);

/** @var Repository $config */
$config = $app['config'];
$config->set('cache.default', 'array');

return $app;
