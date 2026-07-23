<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
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
    PaperQueryBuilder::forgetCache(Article::class);
});

it('persists records created through a Laravel factory', function (): void {
    ArticleFactory::new()->count(3)->create();

    $articles = Article::all();
    $first = $articles->first();

    expect($articles)->toHaveCount(3)
        ->and(Storage::disk('paper')->exists('articles/'.$first->slug.'.md'))->toBeTrue();
});
