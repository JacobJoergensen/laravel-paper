<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

function writeAggregatePost(string $slug, string $frontmatter): void
{
    $path = __DIR__.'/../content/posts/__agg_test__'.$slug.'.md';
    file_put_contents($path, "---\n".$frontmatter."\n---\n\nbody\n");
}

beforeEach(function (): void {
    Post::resetPaperState();
});

afterEach(function (): void {
    foreach (glob(__DIR__.'/../content/posts/__agg_test__*') ?: [] as $file) {
        @unlink($file);
    }
});

it('returns zero for sum and null for min, max and avg when no numeric values match', function (Closure $query, string $column): void {
    $builder = $query();

    expect($builder->sum($column))->toBe(0)
        ->and($builder->min($column))->toBeNull()
        ->and($builder->max($column))->toBeNull()
        ->and($builder->avg($column))->toBeNull();
})->with([
    'empty result set' => [fn (): PaperQueryBuilder => Post::where('slug', 'does-not-exist'), 'order'],
    'column missing from every record' => [fn (): PaperQueryBuilder => Post::query(), 'views'],
]);

it('skips non-numeric and null values when summing and averaging a numeric column', function (): void {
    writeAggregatePost('numeric', 'rating: "5"');
    writeAggregatePost('integer', 'rating: 3');
    writeAggregatePost('text', 'rating: nope');
    writeAggregatePost('absent', 'title: No Rating');

    expect(Post::sum('rating'))->toBe(8)
        ->and(Post::avg('rating'))->toBe(4);
});

it('aggregates only the records matching a where clause', function (): void {
    expect(Post::where('published', true)->sum('order'))->toBe(3);
});

it('average returns the identical result to avg', function (): void {
    expect(Post::average('order'))->toBe(2)
        ->and(Post::average('order'))->toBe(Post::avg('order'));
});

it('ignores orderBy and limit when aggregating, matching SQL', function (): void {
    $query = Post::query()->orderBy('order')->limit(1);

    expect($query->sum('order'))->toBe(6)
        ->and($query->max('order'))->toBe(3);
});

it('aggregates the cast value rather than the raw frontmatter string', function (): void {
    writeAggregatePost('cast', 'views: "3"');

    expect(Post::min('views'))->toBe(3);
});
