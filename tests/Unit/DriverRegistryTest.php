<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidDriverException;

it('resolves a registered driver', function (): void {
    $registry = new DriverRegistry;
    $registry->register('custom', JsonDriver::class);

    expect($registry->resolve('custom'))->toBeInstanceOf(JsonDriver::class);
});

it('throws for an unregistered driver', function (): void {
    new DriverRegistry()->resolve('missing');
})->throws(InvalidDriverException::class);
