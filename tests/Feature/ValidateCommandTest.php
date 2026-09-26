<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\BrokenModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\TenantPost;

beforeEach(function (): void {
    Post::resetPaperState();
    TenantPost::$tenant = 'a';
});

it('reports every malformed file and fails, catching both parse and cast errors', function (): void {
    $this->artisan('paper:validate', ['model' => [BrokenModel::class]])
        ->assertFailed()
        ->expectsOutputToContain('broken-yaml.md')
        ->expectsOutputToContain('broken-date.md')
        ->doesntExpectOutputToContain('valid.md');
});

it('passes a model whose files all parse and cast, reporting how many it checked', function (): void {
    $this->artisan('paper:validate', ['model' => [Post::class]])
        ->assertSuccessful()
        ->expectsOutputToContain('3 files valid');
});

it('says no files were found rather than reporting a content path that matched nothing as valid', function (): void {
    $directory = base_path('tests/content/tenants/__validate_test__');
    mkdir($directory);
    TenantPost::$tenant = '__validate_test__';

    try {
        $this->artisan('paper:validate', ['model' => [TenantPost::class]])
            ->assertSuccessful()
            ->expectsOutputToContain('no content files found')
            ->run();
    } finally {
        rmdir($directory);
    }
});

it('fails when the content path does not exist', function (): void {
    TenantPost::$tenant = '__missing__';

    $this->artisan('paper:validate', ['model' => [TenantPost::class]])
        ->assertFailed()
        ->expectsOutputToContain('__missing__');
});

it('fails on a file that is never read because another claims its slug', function (): void {
    $dir = base_path('tests/content/posts');
    file_put_contents($dir.'/__validate_test__dupe.md', "---\ntitle: Read\n---\n");
    file_put_contents($dir.'/__validate_test__dupe.markdown', "---\ntitle: Ignored\n---\n");

    try {
        $this->artisan('paper:validate', ['model' => [Post::class]])
            ->assertFailed()
            ->expectsOutputToContain('__validate_test__dupe.markdown')
            ->run();
    } finally {
        @unlink($dir.'/__validate_test__dupe.md');
        @unlink($dir.'/__validate_test__dupe.markdown');
    }
});

it('prints failures as a JSON document when --json is passed', function (): void {
    $this->artisan('paper:validate', ['model' => [BrokenModel::class], '--json' => true])
        ->assertFailed()
        ->expectsOutputToContain('"model":');
});

it('rejects the whole run without printing JSON when one argument is not a Paper model', function (): void {
    $this->artisan('paper:validate', ['model' => [Post::class, stdClass::class], '--json' => true])
        ->assertExitCode(2)
        ->expectsOutputToContain('is not a Paper model')
        ->doesntExpectOutputToContain('[]');
});

it('covers every Paper model in app/Models when none are named', function (): void {
    $this->app->useAppPath(base_path('tests/Fixtures/DiscoveryApp'));

    $this->artisan('paper:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('DiscoveredPost');
});

it('fails instead of silently passing when the app has no Paper models', function (): void {
    $this->artisan('paper:validate')
        ->assertExitCode(2)
        ->expectsOutputToContain('No Paper models found');
});
