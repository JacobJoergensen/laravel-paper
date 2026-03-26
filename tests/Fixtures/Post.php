<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

#[Driver('markdown')]
#[ContentPath('tests/content/posts')]
final class Post extends Model
{
    use Paper;

    public function scopePublished(PaperQueryBuilder $query): PaperQueryBuilder
    {
        return $query->where('published', true);
    }

    public function author(): ?Author
    {
        return $this->belongsToPaper(Author::class);
    }
}
