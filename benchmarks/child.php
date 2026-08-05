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
    'bodies' => static fn (): Collection => BenchmarkPost::where('published', true)->pluck('content'),
    'page' => static function (): void {
        BenchmarkPost::find('post-00001');
        BenchmarkPost::where('published', true)->orderByDesc('date')->limit(5)->get();
        BenchmarkPost::count();
    },
    default => throw new InvalidArgumentException("unknown shape '$shape'; expected find, where, bodies, count, paginate, or page"),
};

BenchmarkPost::query();

// Cold and hot are both single-shot; they differ only in whether the caller left a
// manifest behind in a store that outlives the process.
if ($mode !== 'warm') {
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
