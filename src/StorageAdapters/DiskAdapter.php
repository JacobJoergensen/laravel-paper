<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use JacobJoergensen\LaravelPaper\Contracts\StorageAdapterContract;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToRetrieveMetadata;

final readonly class DiskAdapter implements StorageAdapterContract
{
    public function __construct(
        private Filesystem $disk,
        private string $name,
    ) {}

    public function read(string $path): ?string
    {
        $contents = $this->disk->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function write(string $path, string $contents): bool
    {
        return $this->disk->put($path, $contents);
    }

    public function delete(string $path): bool
    {
        return $this->disk->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    public function lastModified(string $path): ?int
    {
        try {
            return $this->disk->lastModified($path);
        } catch (UnableToRetrieveMetadata) {
            return null;
        }
    }

    public function cacheKey(string $path): string
    {
        return 'disk:'.$this->name.':'.$path;
    }

    public function ensureDirectoryExists(string $path): void
    {
        $this->disk->makeDirectory($path);
    }

    /**
     * @param  list<string>  $extensions
     * @return array<string, int>
     */
    public function listing(string $directory, array $extensions): array
    {
        $allowed = array_flip($extensions);
        $matches = [];

        if ($this->disk instanceof FilesystemAdapter) {
            foreach ($this->disk->getDriver()->listContents($directory, false) as $attributes) {
                if (! $attributes instanceof FileAttributes) {
                    continue;
                }

                $path = $attributes->path();
                $ext = pathinfo($path, PATHINFO_EXTENSION);

                if (isset($allowed[$ext])) {
                    $matches[$path] = $attributes->lastModified() ?? 0;
                }
            }

            return $matches;
        }

        foreach ($this->disk->files($directory) as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);

            if (isset($allowed[$ext])) {
                $matches[$file] = $this->lastModified($file) ?? 0;
            }
        }

        return $matches;
    }
}
