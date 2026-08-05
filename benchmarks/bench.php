<?php

declare(strict_types=1);

const COLD_SAMPLES = 15;
const WARM_SAMPLES = 50;

if (! is_dir(__DIR__.'/.opcache')) {
    mkdir(__DIR__.'/.opcache', 0777, true);
}

$seed = (int) (getenv('BENCH_SEED') ?: '1');
$counts = [100, 1000, 5000];

$rows = [];

foreach ($counts as $count) {
    generate($count, $seed);

    progress("find / $count / cold");
    $rows[] = ['find($slug)', $count, 'cold', coldStats('find')];

    progress("find / $count / hot");
    $rows[] = ['find($slug)', $count, 'hot', hotStats('find')];

    progress("where()->get() / $count / cold");
    $rows[] = ['where()->get()', $count, 'cold', coldStats('where')];

    progress("where()->get() / $count / hot");
    $rows[] = ['where()->get()', $count, 'hot', hotStats('where')];

    progress("where()->get() / $count / warm");
    $rows[] = ['where()->get()', $count, 'warm', warmStats('where')];

    progress("read every body / $count / hot");
    $rows[] = ['read every body', $count, 'hot', hotStats('bodies')];

    progress("count() / $count / cold");
    $rows[] = ['count()', $count, 'cold', coldStats('count')];

    progress("paginate(15) / $count / cold");
    $rows[] = ['paginate(15)', $count, 'cold', coldStats('paginate')];

    progress("paginate(15) / $count / hot");
    $rows[] = ['paginate(15)', $count, 'hot', hotStats('paginate')];

    progress("paginate(15) / $count / warm");
    $rows[] = ['paginate(15)', $count, 'warm', warmStats('paginate')];

    progress("page of 3 queries / $count / hot");
    $rows[] = ['page of 3 queries', $count, 'hot', hotStats('page')];
}

generate(10000, $seed);

progress('find / 10000 / cold');
$rows[] = ['find($slug)', 10000, 'cold', coldStats('find')];

progress('find / 10000 / hot');
$rows[] = ['find($slug)', 10000, 'hot', hotStats('find')];

progress('where()->get() / 10000 / cold');
$rows[] = ['where()->get()', 10000, 'cold', coldStats('where')];

progress('where()->get() / 10000 / hot');
$rows[] = ['where()->get()', 10000, 'hot', hotStats('where')];

$cold = [];
$hot = [];

foreach ([1, 50] as $bodyKb) {
    generate(1000, $seed, $bodyKb);

    progress("validation / {$bodyKb}KB / cold");
    $cold[$bodyKb.'KB'] = coldStats('where');

    progress("validation / {$bodyKb}KB / hot");
    $hot[$bodyKb.'KB'] = hotStats('where');
}

writeResults($rows, validationSection($cold, $hot, 1000), $seed, $counts);

progress('done — benchmarks/RESULTS.md');

function generate(int $count, int $seed, int $bodyKb = 0): void
{
    run(escapeshellarg(__DIR__.'/generate.php').' '.$count.' '.$seed.' '.$bodyKb);
}

/**
 * @return array{median: float, min: float, p90: float, peak: float}
 */
function coldStats(string $shape): array
{
    $samples = [];

    for ($i = 0; $i < COLD_SAMPLES; $i++) {
        $samples[] = sample($shape, 'cold')[0];
    }

    return stats($samples);
}

/**
 * @return array{median: float, min: float, p90: float, peak: float}
 */
function warmStats(string $shape): array
{
    return stats(sample($shape, 'warm'));
}

/**
 * @return array{median: float, min: float, p90: float, peak: float}
 */
function hotStats(string $shape): array
{
    putenv('BENCH_CACHE_STORE=file');
    flushStore();

    // The first process builds and persists the manifest, the measured ones only read it.
    sample($shape, 'hot');

    $samples = [];

    for ($i = 0; $i < COLD_SAMPLES; $i++) {
        $samples[] = sample($shape, 'hot')[0];
    }

    putenv('BENCH_CACHE_STORE=array');

    return stats($samples);
}

function flushStore(): void
{
    $entries = glob(dirname(__DIR__).'/storage/framework/cache/data/*/*/*') ?: [];

    foreach ($entries as $entry) {
        @unlink($entry);
    }
}

/**
 * @return list<array{0: int, 1: int}>
 */
function sample(string $shape, string $mode): array
{
    $count = $mode === 'warm' ? WARM_SAMPLES : 1;
    $output = run(escapeshellarg(__DIR__.'/child.php').' '.$mode.' '.$shape.' '.$count);

    $pairs = [];

    foreach ($output as $line) {
        if (preg_match('/^(\d+) (\d+)$/', trim($line), $matches) === 1) {
            $pairs[] = [(int) $matches[1], (int) $matches[2]];
        }
    }

    return $pairs;
}

/**
 * @return list<string>
 */
function run(string $arguments): array
{
    // A shared opcache file cache keeps bytecode warm across the fresh cold processes,
    // so cold runs measure an empty application cache, not PHP recompiling Laravel each time.
    $flags = '-d opcache.enable_cli=1 -d '.escapeshellarg('opcache.file_cache='.__DIR__.'/.opcache');

    $output = [];
    $code = 0;

    exec(escapeshellarg(PHP_BINARY).' '.$flags.' '.$arguments, $output, $code);

    if ($code !== 0) {
        fwrite(STDERR, 'command failed: '.$arguments.PHP_EOL.implode(PHP_EOL, $output).PHP_EOL);
        exit(1);
    }

    return $output;
}

/**
 * @param  list<array{0: int, 1: int}>  $pairs
 * @return array{median: float, min: float, p90: float, peak: float}
 */
function stats(array $pairs): array
{
    $times = array_map(static fn (array $pair): int => $pair[0], $pairs);
    $peaks = array_map(static fn (array $pair): int => $pair[1], $pairs);

    sort($times);
    sort($peaks);

    return [
        'median' => median($times) / 1e6,
        'min' => $times[0] / 1e6,
        'p90' => percentile($times, 0.9) / 1e6,
        'peak' => median($peaks) / (1024 * 1024),
    ];
}

/**
 * @param  list<int>  $sorted
 */
function median(array $sorted): float
{
    $middle = intdiv(count($sorted), 2);

    if (count($sorted) % 2 === 1) {
        return (float) $sorted[$middle];
    }

    return ($sorted[$middle - 1] + $sorted[$middle]) / 2;
}

/**
 * @param  list<int>  $sorted
 */
function percentile(array $sorted, float $quantile): float
{
    $rank = (int) ceil($quantile * count($sorted)) - 1;

    return (float) $sorted[max(0, min($rank, count($sorted) - 1))];
}

/**
 * @param  list<array{0: string, 1: int, 2: string, 3: array{median: float, min: float, p90: float, peak: float}}>  $rows
 * @param  list<int>  $counts
 */
function writeResults(array $rows, string $validation, int $seed, array $counts): void
{
    $body = "# Benchmark results\n\n";
    $body .= machineBlock($seed, $counts);
    $body .= "\nCold runs measure a fresh PHP process with an empty application cache. Page cache and PHP opcache stay warm, so this is a first request after a deploy, not a bare-metal disk read.\n";
    $body .= "\nHot runs measure a fresh process against a manifest already held in the file store, which is what a request hits in production once the cache is populated.\n";

    if (PHP_OS_FAMILY === 'Windows') {
        $body .= "\nPHP's `glob()` is far slower on Windows than on glibc, by more than an order of magnitude, so `count()` and `paginate(15)` are listing-bound here.\n";
    }

    $body .= "\n";
    $body .= "| shape | files | cache | median | min | p90 | peak MB |\n";
    $body .= "|-------|------:|-------|-------:|----:|----:|--------:|\n";

    foreach ($rows as [$shape, $files, $cache, $stat]) {
        $body .= sprintf(
            "| %s | %s | %s | %s | %s | %s | %s |\n",
            $shape,
            number_format($files),
            $cache,
            ms($stat['median']),
            ms($stat['min']),
            ms($stat['p90']),
            number_format($stat['peak'], 1)
        );
    }

    file_put_contents(__DIR__.'/RESULTS.md', $body.$validation);
}

/**
 * @param  array<string, array{median: float, min: float, p90: float, peak: float}>  $cold
 * @param  array<string, array{median: float, min: float, p90: float, peak: float}>  $hot
 */
function validationSection(array $cold, array $hot, int $files): string
{
    $body = "\n## File-size validation\n\n";
    $body .= sprintf("`where()->get()` over %s files, 1KB vs 50KB bodies:\n\n", number_format($files));
    $body .= "| cache | 1KB | 50KB | ratio |\n";
    $body .= "|-------|----:|-----:|------:|\n";

    foreach (['cold' => $cold, 'hot' => $hot] as $cache => $stats) {
        $ratio = $stats['50KB']['median'] / $stats['1KB']['median'];

        $body .= sprintf(
            "| %s | %s | %s | %.2f× |\n",
            $cache,
            ms($stats['1KB']['median']),
            ms($stats['50KB']['median']),
            $ratio,
        );
    }

    $hotRatio = $hot['50KB']['median'] / $hot['1KB']['median'];

    return $body.($hotRatio >= 1.5
        ? "\nFile size is decision-relevant on the hot path and kept as an axis.\n"
        : "\nFile size is not decision-relevant in either cache state; the axis is dropped per the design.\n");
}

/**
 * @param  list<int>  $counts
 */
function machineBlock(int $seed, array $counts): string
{
    $jitSetting = (string) ini_get('opcache.jit');
    $jit = $jitSetting === '' || $jitSetting === '0' || $jitSetting === 'disable' ? 'off' : 'on';

    $lines = [
        '- PHP '.PHP_VERSION." (opcache: on via shared file cache, jit: $jit)",
        '- OS '.php_uname('s').' '.php_uname('r'),
        '- CPU '.(getenv('BENCH_CPU') ?: php_uname('m')),
        '- Storage '.(getenv('BENCH_STORAGE') ?: 'unspecified'),
        '- RAM '.(getenv('BENCH_RAM') ?: 'unspecified'),
        '- Fixture seed '.$seed.', cold samples '.COLD_SAMPLES.', warm samples '.WARM_SAMPLES,
        '- File counts '.implode(' / ', array_map(static fn (int $c): string => number_format($c), $counts)).' (+ 10,000 cold-only)',
    ];

    return implode("\n", $lines)."\n";
}

function ms(float $value): string
{
    return number_format($value, 1);
}

function progress(string $message): void
{
    fwrite(STDERR, '› '.$message.PHP_EOL);
}
