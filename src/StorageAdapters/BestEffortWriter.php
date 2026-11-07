<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

use JacobJoergensen\LaravelPaper\Contracts\ConditionalWriteContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;

final readonly class BestEffortWriter implements ConditionalWriteContract
{
    public function __construct(
        private StorageAdapterContract $adapter,
    ) {}

    public static function of(StorageAdapterContract $adapter): ConditionalWriteContract
    {
        return $adapter instanceof ConditionalWriteContract ? $adapter : new self($adapter);
    }

    /**
     * @return array{contents: string, version: string}|null
     */
    public function readVersioned(string $path): ?array
    {
        $contents = $this->adapter->read($path);

        if ($contents === null) {
            return null;
        }

        return ['contents' => $contents, 'version' => $this->version($contents)];
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function createIfMissing(string $path, string $contents, array $conflicts = []): ConditionalWriteResult
    {
        $taken = $this->firstTaken([$path, ...$conflicts]);

        return $taken ?? $this->write($path, $contents);
    }

    public function replaceIf(string $path, string $contents, ?string $version): ConditionalWriteResult
    {
        $mismatch = $this->verify($path, $version);

        return $mismatch ?? $this->write($path, $contents);
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function moveIf(string $from, string $to, string $contents, ?string $version, array $conflicts = []): ConditionalWriteResult
    {
        $mismatch = $this->verify($from, $version);

        if ($mismatch !== null) {
            return $mismatch;
        }

        $written = $this->createIfMissing($to, $contents, $conflicts);

        if ($written->status !== ConditionalWriteStatus::Written) {
            return $written;
        }

        if ($this->adapter->delete($from)) {
            return $written;
        }

        $this->deleteIf($to, $written->version);

        return ConditionalWriteResult::failed();
    }

    public function deleteIf(string $path, ?string $version): ConditionalWriteResult
    {
        $mismatch = $this->verify($path, $version);

        if ($mismatch !== null) {
            return $mismatch;
        }

        return $this->adapter->delete($path)
            ? ConditionalWriteResult::removed()
            : ConditionalWriteResult::failed();
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstTaken(array $paths): ?ConditionalWriteResult
    {
        foreach ($paths as $path) {
            if ($this->adapter->exists($path)) {
                return ConditionalWriteResult::taken($path);
            }
        }

        return null;
    }

    private function verify(string $path, ?string $version): ?ConditionalWriteResult
    {
        if ($version === null) {
            return null;
        }

        $current = $this->readVersioned($path);

        if ($current === null) {
            return ConditionalWriteResult::missing();
        }

        return $current['version'] === $version ? null : ConditionalWriteResult::mismatch();
    }

    private function write(string $path, string $contents): ConditionalWriteResult
    {
        return $this->adapter->write($path, $contents)
            ? ConditionalWriteResult::written($this->version($contents))
            : ConditionalWriteResult::failed();
    }

    private function version(string $contents): string
    {
        return hash('sha256', $contents);
    }
}
