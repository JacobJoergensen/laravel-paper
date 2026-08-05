<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;

final class CountingStore extends ArrayStore
{
    /** @var array<string, int> */
    public array $counts = ['get' => 0, 'put' => 0];

    public function reset(): void
    {
        $this->counts = array_fill_keys(array_keys($this->counts), 0);
    }

    public function get($key): mixed
    {
        $this->counts['get']++;

        return parent::get($key);
    }

    public function forever($key, $value): bool
    {
        $this->counts['put']++;

        return parent::forever($key, $value);
    }
}
