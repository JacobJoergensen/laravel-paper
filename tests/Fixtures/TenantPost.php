<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('markdown')]
final class TenantPost extends Model
{
    use Paper;

    public static string $tenant = 'a';

    /** @var list<string> */
    protected $guarded = [];

    public function getContentPath(): string
    {
        return 'tests/content/tenants/'.self::$tenant;
    }
}
