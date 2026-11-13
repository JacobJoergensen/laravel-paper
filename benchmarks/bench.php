<?php

declare(strict_types=1);

const PROCESS_SAMPLES = 15;
const WARM_SAMPLES = 50;
const PRIMING_RUNS = 2;

const SHAPES = [
    'find' => 'find($slug)',
    'where' => 'where()->get()',
    'bodies' => 'read every body',
    'count' => 'count()',
    'count-where' => 'where()->count()',
    'pluck' => 'pluck($column)',
    'paginate' => 'paginate(15)',
    'page' => 'page of 3 queries',
];

if (! is_dir(__DIR__.'/.opcache')) {
    mkdir(__DIR__.'/.opcache', 0777, true);
}

$seed = (int) (getenv('BENCH_SEED') ?: '1');
$counts = [100, 1000, 5000];

$rows = [];

foreach ($counts as $count) {
    generate($count, $seed);

    $rows[] = measure('find', $count, 'cold');
    $rows[] = measure('find', $count, 'hot');
    $rows[] = measure('where', $count, 'cold');
    $rows[] = measure('where', $count, 'hot');
    $rows[] = measure('where', $count, 'warm');
    $rows[] = measure('bodies', $count, 'hot');
    $rows[] = measure('count', $count, 'cold');
    $rows[] = measure('count-where', $count, 'hot');
    $rows[] = measure('pluck', $count, 'hot');
    $rows[] = measure('paginate', $count, 'cold');
    $rows[] = measure('paginate', $count, 'hot');
    $rows[] = measure('paginate', $count, 'warm');
    $rows[] = measure('page', $count, 'hot');
}

generate(10000, $seed);

$rows[] = measure('find', 10000, 'cold');
$rows[] = measure('find', 10000, 'hot');
$rows[] = measure('where', 10000, 'cold');
$rows[] = measure('where', 10000, 'hot');

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
 * @param  key-of<SHAPES>  $shape
 * @param  'cold'|'hot'|'warm'  $cache
 * @return array{0: string, 1: int, 2: string, 3: array{median: float, min: float, p90: float, peak: float}}
 */
function measure(string $shape, int $files, string $cache): array
{
    $label = SHAPES[$shape];

    progress("$label / $files / $cache");

    $stats = match ($cache) {
        'cold' => coldStats($shape),
        'hot' => hotStats($shape),
        'warm' => warmStats($shape),
    };

    return [$label, $files, $cache, $stats];
}

/**
 * @return array{median: float, min: float, p90: float, peak: float}
 */
function coldStats(string $shape): array
{
    $samples = [];

    for ($i = 0; $i < PROCESS_SAMPLES; $i++) {
        flushStore();
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
    flushStore();

    for ($i = 0; $i < PRIMING_RUNS; $i++) {
        sample($shape, 'hot');
    }

    $samples = [];

    for ($i = 0; $i < PROCESS_SAMPLES; $i++) {
        $samples[] = sample($shape, 'hot')[0];
    }

    return stats($samples);
}

function flushStore(): void
{
    $data = dirname(__DIR__).'/storage/framework/cache/data';

    foreach (['/*/*/*', '/*/*', '/*'] as $depth) {
        foreach (glob($data.$depth) ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
    }

    $compiled = dirname(__DIR__).'/bootstrap/cache/paper-manifest-*.php';

    foreach (glob($compiled) ?: [] as $file) {
        unlink($file);
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
    $body .= "\nHot runs measure a fresh process against a manifest already compiled for opcache. Here opcache loads it from its file cache; under FPM it stays in shared memory, which is faster.\n";

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

    $ratios = [];

    foreach (['cold' => $cold, 'hot' => $hot] as $cache => $stats) {
        $ratios[$cache] = $stats['50KB']['median'] / $stats['1KB']['median'];

        $body .= sprintf(
            "| %s | %s | %s | %.2f× |\n",
            $cache,
            ms($stats['1KB']['median']),
            ms($stats['50KB']['median']),
            $ratios[$cache],
        );
    }

    return $body.($ratios['hot'] >= 1.5
        ? "\nFile size is decision-relevant on the hot path.\n"
        : "\nFile size is not decision-relevant on the hot path.\n");
}

/**
 * @param  list<int>  $counts
 */
function machineBlock(int $seed, array $counts): string
{
    $jitSetting = (string) ini_get('opcache.jit');
    $jit = $jitSetting === '' || $jitSetting === '0' || $jitSetting === 'disable' ? 'off' : 'on';
    $fileCounts = implode(' / ', array_map(static fn (int $c): string => number_format($c), $counts));

    $lines = [
        '- PHP '.PHP_VERSION." (opcache: on via shared file cache, jit: $jit)",
        '- OS '.php_uname('s').' '.php_uname('r'),
        '- CPU '.(getenv('BENCH_CPU') ?: php_uname('m')),
        '- Storage '.(getenv('BENCH_STORAGE') ?: 'unspecified'),
        '- RAM '.(getenv('BENCH_RAM') ?: 'unspecified'),
        '- Paper watch off, so cold and hot rows take the production path',
        '- Fixture seed '.$seed.', process samples '.PROCESS_SAMPLES.', warm samples '.WARM_SAMPLES,
        '- File counts '.$fileCounts.' (+ 10,000 cold and hot)',
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
