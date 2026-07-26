<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\Tests\Fixtures\BrokenModel;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

it('reports every malformed file and fails, catching both parse and cast errors', function (): void {
    $this->artisan('paper:validate', ['model' => [BrokenModel::class]])
        ->assertFailed()
        ->expectsOutputToContain('broken-yaml.md')
        ->expectsOutputToContain('broken-date.md')
        ->doesntExpectOutputToContain('valid.md');
});

it('passes a model whose files all parse and cast', function (): void {
    $this->artisan('paper:validate', ['model' => [Post::class]])->assertSuccessful();
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
