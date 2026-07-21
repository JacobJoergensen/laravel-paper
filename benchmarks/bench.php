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

    progress("where()->get() / $count / cold");
    $rows[] = ['where()->get()', $count, 'cold', coldStats('where')];

    progress("where()->get() / $count / warm");
    $rows[] = ['where()->get()', $count, 'warm', warmStats('where')];

    progress("count() / $count / cold");
    $rows[] = ['count()', $count, 'cold', coldStats('count')];

    progress("paginate(15) / $count / cold");
    $rows[] = ['paginate(15)', $count, 'cold', coldStats('paginate')];

    progress("paginate(15) / $count / warm");
    $rows[] = ['paginate(15)', $count, 'warm', warmStats('paginate')];
}

generate(10000, $seed);

progress('find / 10000 / cold');
$rows[] = ['find($slug)', 10000, 'cold', coldStats('find')];

progress('where()->get() / 10000 / cold');
$rows[] = ['where()->get()', 10000, 'cold', coldStats('where')];

generate(1000, $seed, 1);
progress('validation / 1KB');
$small = coldStats('where');

generate(1000, $seed, 50);
progress('validation / 50KB');
$large = coldStats('where');

writeResults($rows, $small, $large, $seed, $counts);

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
 * @param  array{median: float, min: float, p90: float, peak: float}  $small
 * @param  array{median: float, min: float, p90: float, peak: float}  $large
 * @param  list<int>  $counts
 */
function writeResults(array $rows, array $small, array $large, int $seed, array $counts): void
{
    $body = "# Benchmark results\n\n";
    $body .= machineBlock($seed, $counts);
    $body .= "\nCold runs measure a fresh PHP process with an empty application cache. Page cache and PHP opcache stay warm, so this is a first request after a deploy, not a bare-metal disk read.\n";

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

    $ratio = $large['median'] / $small['median'];
    $dominant = $ratio >= 1.5;

    $body .= "\n## File-size validation\n\n";
    $body .= "`where()->get()` cold over 1,000 files, 1KB vs 50KB bodies:\n\n";
    $body .= "| body | median | min | p90 |\n";
    $body .= "|------|-------:|----:|----:|\n";
    $body .= sprintf("| 1KB | %s | %s | %s |\n", ms($small['median']), ms($small['min']), ms($small['p90']));
    $body .= sprintf("| 50KB | %s | %s | %s |\n", ms($large['median']), ms($large['min']), ms($large['p90']));
    $body .= sprintf("\n50KB is %.2f× the 1KB time. ", $ratio);

    $body .= $dominant
        ? "File size is decision-relevant and kept as an axis.\n"
        : "Paper parses only the frontmatter, so file size is not decision-relevant; the axis is dropped per the design.\n";

    file_put_contents(__DIR__.'/RESULTS.md', $body);
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
