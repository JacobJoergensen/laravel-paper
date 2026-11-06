<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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
    'count-where' => static fn (): int => BenchmarkPost::where('published', true)->count(),
    'pluck' => static fn (): Collection => BenchmarkPost::pluck('title'),
    'paginate' => static fn (): LengthAwarePaginator => BenchmarkPost::paginate(),
    'bodies' => static fn (): Collection => BenchmarkPost::where('published', true)->pluck('content'),
    'page' => static function (): void {
        BenchmarkPost::find('post-00001');
        BenchmarkPost::where('published', true)->orderByDesc('date')->limit(5)->get();
        BenchmarkPost::count();
    },
    default => throw new InvalidArgumentException("unknown shape '$shape'; expected find, where, bodies, count, count-where, pluck, paginate, or page"),
};

BenchmarkPost::query();

Carbon::now();

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
