<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

final class PostObserver
{
    /** @var array<int, string> */
    public static array $events = [];

    public function retrieved(Post $post): void
    {
        self::$events[] = 'retrieved';
    }

    public function created(Post $post): void
    {
        self::$events[] = 'created';
    }

    public function deleted(Post $post): void
    {
        self::$events[] = 'deleted';
    }
}
