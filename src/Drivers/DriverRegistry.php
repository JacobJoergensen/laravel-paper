<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Drivers;

use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidDriverException;

final class DriverRegistry
{
    /** @var array<string, class-string<DriverContract>> */
    private array $drivers = [];

    /**
     * @param  class-string<DriverContract>  $driver
     */
    public function register(string $name, string $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function resolve(string $name): DriverContract
    {
        if (! isset($this->drivers[$name])) {
            throw InvalidDriverException::notFound($name);
        }

        return app($this->drivers[$name]);
    }
}
