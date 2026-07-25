<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Exceptions\PaperException;

arch('lets every package exception be caught as a PaperException')
    ->expect('JacobJoergensen\LaravelPaper\Exceptions')
    ->toImplement(PaperException::class)
    ->ignoring(PaperException::class);
