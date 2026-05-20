<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Exceptions\ContentPathNotFoundException;
use JacobJoergensen\LaravelPaper\Exceptions\FileParseException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidDriverException;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\Exceptions\PaperException;

it('lets every package exception be caught as a PaperException', function (string $exception): void {
    expect(is_subclass_of($exception, PaperException::class))->toBeTrue();
})->with([
    ContentPathNotFoundException::class,
    FileParseException::class,
    InvalidDriverException::class,
    InvalidSlugException::class,
]);
