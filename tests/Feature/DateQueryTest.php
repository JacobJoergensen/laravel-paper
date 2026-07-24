<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use JacobJoergensen\LaravelPaper\Cache\PaperManifest;
use JacobJoergensen\LaravelPaper\Drivers\MarkdownDriver;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DateModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawDateModel;

beforeEach(function (): void {
    $this->timezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Copenhagen');

    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/march.md', "---\ndate: 2024-03-15\n---\n", 1_000);
    $adapter->seed('blog/june.md', "---\ndate: '2025-06-20'\n---\n", 2_000);

    $this->raw = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawDateModel::class);
    $this->cast = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', DateModel::class);
});

afterEach(function (): void {
    date_default_timezone_set($this->timezone);
});

it('filters by year, month, day, and date', function (): void {
    expect(($this->raw)()->whereYear('date', 2024)->get()->pluck('slug')->all())->toBe(['march'])
        ->and(($this->raw)()->whereYear('date', '>', 2024)->get()->pluck('slug')->all())->toBe(['june'])
        ->and(($this->raw)()->whereMonth('date', 6)->get()->pluck('slug')->all())->toBe(['june'])
        ->and(($this->raw)()->whereDay('date', 15)->get()->pluck('slug')->all())->toBe(['march'])
        ->and(($this->raw)()->whereDate('date', '2024-03-15')->get()->pluck('slug')->all())->toBe(['march']);
});

it('accepts a Carbon value', function (): void {
    expect(($this->raw)()->whereDate('date', Carbon::parse('2024-03-15'))->get()->pluck('slug')->all())->toBe(['march'])
        ->and(($this->raw)()->whereYear('date', Carbon::parse('2025-01-01'))->get()->pluck('slug')->all())->toBe(['june']);
});

it('gives the same answer whether the date column is cast or raw', function (): void {
    $cases = [
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->whereYear('date', 2024),
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->whereMonth('date', 6),
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->whereDay('date', 20),
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->whereDate('date', '2024-03-15'),
        fn (PaperQueryBuilder $q): PaperQueryBuilder => $q->whereDate('date', '2025-06-20'),
    ];

    foreach ($cases as $case) {
        $raw = $case(($this->raw)())->get()->pluck('slug')->all();
        $cast = $case(($this->cast)())->get()->pluck('slug')->all();

        expect($cast)->toBe($raw);
    }
});
