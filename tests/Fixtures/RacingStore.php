<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;

final class RacingStore extends ArrayStore
{
    public bool $contended = false;

    private ?Closure $duringWrite = null;

    /**
     * @param  Closure(): void  $work
     */
    public function duringNextWrite(Closure $work): void
    {
        $this->duringWrite = $work;
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        if ($this->contended) {
            $this->locks[$name] = ['owner' => 'another-process', 'expiresAt' => Carbon::now()->addMinute()];
        }

        return parent::lock($name, $seconds, $owner);
    }

    public function forever($key, $value): bool
    {
        if ($this->duringWrite !== null) {
            $work = $this->duringWrite;
            $this->duringWrite = null;

            $work();
        }

        return parent::forever($key, $value);
    }
}
