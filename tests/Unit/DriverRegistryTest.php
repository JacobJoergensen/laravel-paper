<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use JacobJoergensen\LaravelPaper\Drivers\JsonDriver;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidDriverException;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Page;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

it('resolves a registered driver', function (): void {
    $registry = new DriverRegistry;
    $registry->register('custom', JsonDriver::class);

    expect($registry->resolve('custom'))->toBeInstanceOf(JsonDriver::class);
});

it('throws for an unregistered driver', function (): void {
    $registry = new DriverRegistry;
    $registry->resolve('missing');
})->throws(InvalidDriverException::class);

it('hands out the driver a model is configured with', function (): void {
    expect(PaperQueryBuilder::driverFor(Post::class)->bodySyntax())->toBe('markdown')
        ->and(PaperQueryBuilder::driverFor(Page::class))->toBeInstanceOf(JsonDriver::class);
});
