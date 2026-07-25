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
use JacobJoergensen\LaravelPaper\Tests\Fixtures\RawModel;

beforeEach(function (): void {
    $this->timezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Copenhagen');

    $manifest = new PaperManifest(new Repository(new ArrayStore), 60, 10, true);
    $adapter = new CountingAdapter;
    $adapter->seed('blog/march.md', "---\ndate: 2024-03-15\n---\n", 1_000);
    $adapter->seed('blog/june.md', "---\ndate: '2025-06-20'\n---\n", 2_000);

    $this->adapter = $adapter;
    $this->raw = fn (): PaperQueryBuilder => new PaperQueryBuilder($adapter, new MarkdownDriver, $manifest, 'blog', RawModel::class);
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
    expect(($this->cast)()->whereYear('date', 2024)->get()->pluck('slug')->all())->toBe(['march'])
        ->and(($this->cast)()->whereMonth('date', 6)->get()->pluck('slug')->all())->toBe(['june'])
        ->and(($this->cast)()->whereDay('date', 15)->get()->pluck('slug')->all())->toBe(['march'])
        ->and(($this->cast)()->whereDate('date', '2024-03-15')->get()->pluck('slug')->all())->toBe(['march']);
});

it('excludes a record whose date cannot be parsed', function (): void {
    $this->adapter->seed('blog/broken.md', "---\ndate: banana\n---\n", 3_000);

    expect(($this->raw)()->count())->toBe(3)
        ->and(($this->raw)()->whereYear('date', 2024)->get()->pluck('slug')->all())->toBe(['march']);
});
