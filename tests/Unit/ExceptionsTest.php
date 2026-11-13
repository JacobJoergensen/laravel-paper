<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Exceptions\PaperException;

arch('every package exception can be caught as a PaperException')
    ->expect('JacobJoergensen\LaravelPaper\Exceptions')
    ->toImplement(PaperException::class);
