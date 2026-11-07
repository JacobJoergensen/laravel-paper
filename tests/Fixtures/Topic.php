<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\Relations\BelongsToManyPaper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaperByList;

#[ContentPath('tests/content/topics')]
final class Topic extends PaperModel
{
    /**
     * @return BelongsToManyPaper<self>
     */
    public function links(): BelongsToManyPaper
    {
        return $this->belongsToManyPaper(self::class);
    }

    /**
     * @return HasManyPaperByList<self>
     */
    public function linkedFrom(): HasManyPaperByList
    {
        return $this->hasManyPaperByList(self::class);
    }
}
