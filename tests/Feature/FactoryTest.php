<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Article;

/**
 * @extends Factory<Article>
 */
final class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'article-'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'title' => $this->faker->sentence(),
        ];
    }
}

beforeEach(function (): void {
    Storage::fake('paper');
    Article::resetPaperState();
});

it('persists records created through a Laravel factory', function (): void {
    $created = ArticleFactory::new()->count(3)->create();
    $first = $created->first();

    expect(Article::all())->toHaveCount(3)
        ->and(Article::find($first->slug)?->title)->toBe($first->title);
});
