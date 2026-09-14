<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

use JacobJoergensen\LaravelPaper\Contracts\ConditionalWriteContract;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use JacobJoergensen\LaravelPaper\PaperVersion;

/**
 * Writes without checking the version, for the concurrency policy that accepts last write wins.
 */
final readonly class UncheckedWriter implements ConditionalWriteContract
{
    public function __construct(
        private StorageAdapterContract $adapter,
    ) {}

    /**
     * @return array{contents: string, version: string}|null
     */
    public function readVersioned(string $path): ?array
    {
        $contents = $this->adapter->read($path);

        if ($contents === null) {
            return null;
        }

        return ['contents' => $contents, 'version' => PaperVersion::of($contents)];
    }

    /**
     * A taken slug is still refused: the policy drops the version check, not the record's identity.
     *
     * @param  list<string>  $conflicts
     */
    public function createIfMissing(string $path, string $contents, array $conflicts = []): ConditionalWriteResult
    {
        $taken = $this->firstTaken([$path, ...$conflicts]);

        return $taken ?? $this->write($path, $contents);
    }

    public function replaceIf(string $path, string $contents, string $version): ConditionalWriteResult
    {
        return $this->write($path, $contents);
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function moveIf(string $from, string $to, string $contents, string $version, array $conflicts = []): ConditionalWriteResult
    {
        $taken = $this->firstTaken([$to, ...$conflicts]);

        if ($taken !== null) {
            return $taken;
        }

        $written = $this->write($to, $contents);

        if ($written->status !== ConditionalWriteStatus::Written) {
            return $written;
        }

        if ($this->adapter->delete($from)) {
            return $written;
        }

        // Only the file this call wrote is rolled back, never whatever stands there now.
        $this->rollback($to, (string) $written->version);

        return ConditionalWriteResult::failed();
    }

    private function rollback(string $path, string $version): void
    {
        $current = $this->adapter->read($path);

        if ($current !== null && PaperVersion::of($current) === $version) {
            $this->adapter->delete($path);
        }
    }

    public function deleteIf(string $path, string $version): ConditionalWriteResult
    {
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

    private function write(string $path, string $contents): ConditionalWriteResult
    {
        return $this->adapter->write($path, $contents)
            ? ConditionalWriteResult::written(PaperVersion::of($contents))
            : ConditionalWriteResult::failed();
    }
}
