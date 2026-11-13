<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;

final class CountingStore extends ArrayStore
{
    /**
     * @var list<string>
     */
    public array $keys = [];

    public function reset(): void
    {
        $this->keys = [];
    }

    public function get($key): mixed
    {
        $this->keys[] = $key;

        return parent::get($key);
    }
}
