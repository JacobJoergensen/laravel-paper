<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
#[CollectedBy(PostCollection::class)]
#[Hidden(['order'])]
#[ObservedBy(PostObserver::class)]
final class Post extends Model
{
    use Paper;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function scopePublished(PaperQueryBuilder $query): PaperQueryBuilder
    {
        return $query->where('published', true);
    }

    #[Scope]
    protected function withOrder(PaperQueryBuilder $query, int $order): PaperQueryBuilder
    {
        return $query->where('order', $order);
    }

    public function author(): ?Author
    {
        return $this->belongsToPaper(Author::class);
    }
}
