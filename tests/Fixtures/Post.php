<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
#[CollectedBy(PostCollection::class)]
#[Fillable(['slug', 'title'])]
#[Hidden(['order'])]
#[ObservedBy(PostObserver::class)]
final class Post extends Model implements PaperModel
{
    use Paper;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tags' => 'array', 'views' => 'integer'];
    }

    /**
     * @param  PaperQueryBuilder<self>  $query
     * @return PaperQueryBuilder<self>
     */
    public function scopePublished(PaperQueryBuilder $query): PaperQueryBuilder
    {
        return $query->where('published', true);
    }

    /**
     * @param  PaperQueryBuilder<self>  $query
     * @return PaperQueryBuilder<self>
     */
    #[Scope]
    protected function withOrder(PaperQueryBuilder $query, int $order): PaperQueryBuilder
    {
        return $query->where('order', $order);
    }

    /**
     * @return BelongsToPaper<Author>
     */
    public function author(): BelongsToPaper
    {
        return $this->belongsToPaper(Author::class);
    }
}
