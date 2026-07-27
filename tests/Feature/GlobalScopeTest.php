<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use JacobJoergensen\LaravelPaper\Exceptions\UnsupportedScopeException;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\PublishedScope;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\ScopedPost;

beforeEach(function (): void {
    ScopedPost::resetPaperState();
});

it('returns only records that pass every global scope', function (): void {
    expect(ScopedPost::all()->pluck('slug')->all())->toBe(['second-post']);
});

it('returns null from find when a global scope excludes the record', function (): void {
    expect(ScopedPost::find('draft-post'))->toBeNull()
        ->and(ScopedPost::find('second-post')?->slug)->toBe('second-post');
});

it('resolves no route binding for a record a global scope excludes', function (): void {
    $post = new ScopedPost;

    expect($post->resolveRouteBinding('hello-world'))->toBeNull()
        ->and($post->resolveRouteBinding('second-post')?->slug)->toBe('second-post');
});

it('removes a single global scope by name or class', function (): void {
    expect(ScopedPost::withoutGlobalScope('later')->get()->pluck('slug')->all())->toBe(['hello-world', 'second-post'])
        ->and(ScopedPost::withoutGlobalScope(PublishedScope::class)->get()->pluck('slug')->all())->toBe(['draft-post', 'second-post']);
});

it('removes every global scope', function (): void {
    $all = ['draft-post', 'hello-world', 'second-post'];

    expect(ScopedPost::withoutGlobalScopes()->get()->pluck('slug')->all())->toBe($all)
        ->and(ScopedPost::withoutGlobalScopes([new PublishedScope, 'later'])->get()->pluck('slug')->all())->toBe($all);
});

it('keeps an or condition from widening past a global scope', function (): void {
    $posts = ScopedPost::where('order', 2)->orWhere('order', 3)->get();

    expect($posts->pluck('slug')->all())->toBe(['second-post']);
});

it('counts and paginates only the records a global scope allows', function (): void {
    expect(ScopedPost::count())->toBe(1)
        ->and(ScopedPost::paginate(10)->total())->toBe(1)
        ->and(ScopedPost::pluck('slug')->all())->toBe(['second-post']);
});

it('rejects a scope written against Eloquent instead of Paper', function (): void {
    $scope = new class implements Scope
    {
        public function apply(Builder $builder, Model $model): void {}
    };

    ScopedPost::addGlobalScope($scope);
})->throws(UnsupportedScopeException::class, 'must be a Closure or implement');
