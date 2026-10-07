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
    private BestEffortWriter $writer;

    public function __construct(
        private StorageAdapterContract $adapter,
    ) {
        $this->writer = new BestEffortWriter($adapter);
    }

    /**
     * @return array{contents: string, version: string}|null
     */
    public function readVersioned(string $path): ?array
    {
        return $this->writer->readVersioned($path);
    }

    /**
     * A taken slug is still refused: the policy drops the version check, not the record's identity.
     *
     * @param  list<string>  $conflicts
     */
    public function createIfMissing(string $path, string $contents, array $conflicts = []): ConditionalWriteResult
    {
        return $this->writer->createIfMissing($path, $contents, $conflicts);
    }

    public function replaceIf(string $path, string $contents, string $version): ConditionalWriteResult
    {
        return $this->adapter->write($path, $contents)
            ? ConditionalWriteResult::written(PaperVersion::of($contents))
            : ConditionalWriteResult::failed();
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function moveIf(string $from, string $to, string $contents, string $version, array $conflicts = []): ConditionalWriteResult
    {
        $written = $this->writer->createIfMissing($to, $contents, $conflicts);

        if ($written->status !== ConditionalWriteStatus::Written) {
            return $written;
        }

        if ($this->adapter->delete($from)) {
            return $written;
        }

        // Only the file this call wrote is rolled back, never whatever stands there now.
        $this->writer->deleteIf($to, (string) $written->version);

        return ConditionalWriteResult::failed();
    }

    public function deleteIf(string $path, string $version): ConditionalWriteResult
    {
        return $this->adapter->delete($path)
            ? ConditionalWriteResult::removed()
            : ConditionalWriteResult::failed();
    }
}
