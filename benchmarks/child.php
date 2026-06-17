<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Benchmarks\BenchmarkPost;

const WARMUP_RUNS = 5;

$app = require __DIR__.'/bootstrap.php';

$mode = $argv[1] ?? 'cold';
$shape = $argv[2] ?? 'where';
$samples = (int) ($argv[3] ?? 50);

$run = match ($shape) {
    'find' => static fn (): ?BenchmarkPost => BenchmarkPost::find('post-00001'),
    'where' => static fn (): Collection => BenchmarkPost::where('published', true)->get(),
    'count' => static fn (): int => BenchmarkPost::count(),
    'paginate' => static fn (): LengthAwarePaginator => BenchmarkPost::paginate(),
    default => throw new InvalidArgumentException("unknown shape '$shape'; expected find, where, count, or paginate"),
};

// Resolve the model's attributes up front; this touches no files, so a cold run stays cold.
BenchmarkPost::query();

if ($mode === 'cold') {
    $start = hrtime(true);
    $run();

    echo (hrtime(true) - $start).' '.memory_get_peak_usage(true).PHP_EOL;

    return;
}

for ($i = 0; $i < WARMUP_RUNS; $i++) {
    $run();
}

for ($i = 0; $i < $samples; $i++) {
    $start = hrtime(true);
    $run();

    echo (hrtime(true) - $start).' '.memory_get_peak_usage(true).PHP_EOL;
}
