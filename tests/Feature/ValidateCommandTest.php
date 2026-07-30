<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\BrokenModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\DiskDoc;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

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
    Storage::fake('paper');
    DiskDoc::resetPaperState();

    $this->artisan('paper:validate', ['model' => [DiskDoc::class]])
        ->assertSuccessful()
        ->expectsOutputToContain('no content files found');
});

it('prints failures as a JSON document when --json is passed', function (): void {
    $this->artisan('paper:validate', ['model' => [BrokenModel::class], '--json' => true])
        ->assertFailed()
        ->expectsOutputToContain('"model":');
});

it('prints no JSON document when the arguments are rejected', function (): void {
    $this->artisan('paper:validate', ['model' => [stdClass::class], '--json' => true])
        ->assertExitCode(2)
        ->doesntExpectOutputToContain('[]');
});
