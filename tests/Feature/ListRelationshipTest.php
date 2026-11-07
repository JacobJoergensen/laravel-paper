<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Exceptions\InvalidSlugException;
use JacobJoergensen\LaravelPaper\PaperCollection;
use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Relations\BelongsToManyPaper;
use JacobJoergensen\LaravelPaper\Testing\PaperFake;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Topic;

beforeEach(function (): void {
    PaperFake::fake(Topic::class, [
        'php' => ['topic_slugs' => ['laravel', 'missing', 'pest', 'laravel']],
        'laravel' => ['topic_slugs' => ['php']],
        'pest' => [],
    ]);
});

it('returns the listed records in list order, skipping duplicates and missing slugs', function (): void {
    expect(Topic::find('php')->links->pluck('slug')->all())->toBe(['laravel', 'pest']);
});

it('eager loads each parent its own listed records, and none for a missing list', function (): void {
    $topics = Topic::with('links')->get()->keyBy('slug');

    expect($topics['php']->links->pluck('slug')->all())->toBe(['laravel', 'pest'])
        ->and($topics['laravel']->links->pluck('slug')->all())->toBe(['php'])
        ->and($topics['pest']->links)->toBeInstanceOf(PaperCollection::class)->toHaveCount(0);
});

it('rejects a list attribute that holds a single slug', function (): void {
    PaperFake::fake(Topic::class, ['php' => ['topic_slugs' => 'laravel']]);

    Topic::find('php')->links;
})->throws(InvalidSlugException::class, 'must be a list of slugs');

it('rejects a list attribute cast to a collection', function (): void {
    $topic = new #[ContentPath('tests/content/topics')] class extends PaperModel
    {
        protected function casts(): array
        {
            return ['topic_slugs' => 'collection'];
        }

        public function links(): BelongsToManyPaper
        {
            return $this->belongsToManyPaper(Topic::class);
        }
    };

    PaperFake::fake($topic::class, ['php' => ['topic_slugs' => ['laravel']]]);

    $topic::find('php')->links;
})->throws(InvalidSlugException::class, 'must be a list of slugs');

it('finds the records that list it, lazily and when eager loaded', function (): void {
    $eager = Topic::with('linkedFrom')->get()->keyBy('slug');

    expect(Topic::find('pest')->linkedFrom->pluck('slug')->all())->toBe(['php'])
        ->and($eager['laravel']->linkedFrom->pluck('slug')->all())->toBe(['php'])
        ->and($eager['php']->linkedFrom->pluck('slug')->all())->toBe(['laravel']);
});

it('counts listed records once each, only when they exist and pass the constraint', function (): void {
    $onlyPest = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('slug', 'pest');

    expect(Topic::has('links', '=', 2)->pluck('slug')->all())->toBe(['php'])
        ->and(Topic::whereHas('links', $onlyPest)->pluck('slug')->all())->toBe(['php']);
});

it('ignores a malformed list on another record when resolving the inverse', function (): void {
    PaperFake::fake(Topic::class, [
        'php' => ['topic_slugs' => 'laravel'],
        'pest' => ['topic_slugs' => ['laravel']],
        'laravel' => [],
    ]);

    $eager = Topic::with('linkedFrom')->get()->keyBy('slug');

    expect($eager['laravel']->linkedFrom->pluck('slug')->all())->toBe(['pest'])
        ->and(Topic::has('linkedFrom')->pluck('slug')->all())->toBe(['laravel']);
});

it('counts the records that list it when filtering by the inverse relation', function (): void {
    $fromLaravel = fn (PaperQueryBuilder $query): PaperQueryBuilder => $query->where('slug', 'laravel');

    expect(Topic::whereHas('linkedFrom', $fromLaravel)->pluck('slug')->all())->toBe(['php']);
});
