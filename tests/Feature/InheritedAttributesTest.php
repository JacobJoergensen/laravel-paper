<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\ExtendedManual;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\LegacyManual;

beforeEach(function (): void {
    ExtendedManual::resetPaperState();
    LegacyManual::resetPaperState();
});

it('reads the content path and driver a parent model declares', function (): void {
    expect(ExtendedManual::find('about')?->title)->toBe('About Us');
});

it('reads the timestamps a parent model declares', function (): void {
    expect(ExtendedManual::find('about')?->updated_at)->not->toBeNull();
});

it('lets a child override an attribute the parent declares', function (): void {
    expect(LegacyManual::find('hello-world')?->title)->toBe('Hello World');
});
