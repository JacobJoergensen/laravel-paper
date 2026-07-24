<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Testing;

trait RefreshesPaperFakes
{
    protected function tearDownRefreshesPaperFakes(): void
    {
        PaperFake::reset();
    }
}
