<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('markdown')]
final class TenantPost extends PaperModel
{
    public static string $tenant = 'a';

    /** @var list<string> */
    protected $guarded = [];

    public function getContentPath(): string
    {
        return 'tests/content/tenants/'.self::$tenant;
    }
}
