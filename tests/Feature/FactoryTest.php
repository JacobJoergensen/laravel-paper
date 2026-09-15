<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Draft;

/**
 * @extends Factory<Draft>
 */
final class DraftFactory extends Factory
{
    protected $model = Draft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'draft-'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'title' => $this->faker->sentence(),
        ];
    }
}

beforeEach(function (): void {
    File::deleteDirectory(__DIR__.'/../content/drafts');
    Draft::resetPaperState();
});

afterEach(function (): void {
    File::deleteDirectory(__DIR__.'/../content/drafts');
});

it('persists records created through a Laravel factory', function (): void {
    $created = DraftFactory::new()->count(3)->create();
    $first = $created->first();

    expect(Draft::all())->toHaveCount(3)
        ->and(Draft::find($first->slug)?->title)->toBe($first->title);
});
