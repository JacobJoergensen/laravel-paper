<?php

declare(strict_types=1);

$count = (int) ($argv[1] ?? 0);
$seed = (int) ($argv[2] ?? 1);
$bodyKb = (int) ($argv[3] ?? 0);

$dir = __DIR__.'/.fixtures/posts';
$manifest = $dir.'/.manifest';
$signature = $count.' '.$seed.' '.$bodyKb;

$current = is_file($manifest) ? trim((string) file_get_contents($manifest)) : '';
$onDisk = count(glob($dir.'/post-*.md') ?: []);

if ($current === $signature && $onDisk === $count) {
    return;
}

if (is_dir($dir)) {
    foreach (glob($dir.'/*') ?: [] as $stale) {
        @unlink($stale);
    }
} else {
    mkdir($dir, 0777, true);
}

mt_srand($seed);

/** @var list<string> $words */
$words = explode(' ', 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris');
$tags = ['laravel', 'php', 'markdown', 'content', 'flat-file', 'eloquent', 'testing', 'design', 'performance', 'release'];
$authors = ['jane-doe', 'john-smith', 'alex-lee', 'sam-ray'];
$ratings = ['low', 'medium', 'high'];

for ($i = 1; $i <= $count; $i++) {
    $lines = ['---'];
    $lines[] = 'title: '.ucfirst($words[array_rand($words)]).' '.ucfirst($words[array_rand($words)]).' '.$i;
    $lines[] = 'published: '.(mt_rand(0, 9) < 7 ? 'true' : 'false');
    $lines[] = 'date: '.sprintf('2024-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28));
    $lines[] = 'order: '.$i;
    $lines[] = 'tags: ['.pickTags($tags).']';

    if (mt_rand(0, 9) < 3) {
        $lines[] = 'author_slug: '.$authors[array_rand($authors)];
    }

    $rating = mt_rand(0, 2);

    if ($rating === 0) {
        $lines[] = 'rating: '.mt_rand(1, 5);
    } elseif ($rating === 1) {
        $lines[] = 'rating: "'.$ratings[array_rand($ratings)].'"';
    }

    $lines[] = 'featured: '.(mt_rand(0, 4) === 0 ? 'true' : 'false');
    $lines[] = '---';
    $lines[] = '';
    $lines[] = body($words, $bodyKb);

    file_put_contents($dir.'/'.sprintf('post-%05d', $i).'.md', implode("\n", $lines)."\n");
}

file_put_contents($manifest, $signature);

/**
 * @param  list<string>  $pool
 */
function pickTags(array $pool): string
{
    $picked = [];

    for ($n = mt_rand(1, 3); $n > 0; $n--) {
        $picked[] = $pool[array_rand($pool)];
    }

    return implode(', ', array_unique($picked));
}

/**
 * @param  list<string>  $words
 */
function body(array $words, int $bodyKb): string
{
    if ($bodyKb > 0) {
        $target = $bodyKb * 1024;
        $out = '';

        while (strlen($out) < $target) {
            $out .= $words[array_rand($words)].' ';
        }

        return rtrim($out);
    }

    $out = [];

    for ($n = mt_rand(50, 400); $n > 0; $n--) {
        $out[] = $words[array_rand($words)];
    }

    return implode(' ', $out);
}
