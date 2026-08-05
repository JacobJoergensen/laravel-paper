<?php

declare(strict_types=1);

use JacobJoergensen\LaravelPaper\PaperQueryBuilder;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\CountingAdapter;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Page;
use JacobJoergensen\LaravelPaper\Tests\Fixtures\Post;

beforeEach(function (): void {
    $path = PaperQueryBuilder::contentPathFor(Post::class);

    $this->adapter = new CountingAdapter;
    $this->adapter->seed("$path/a.md", "---\ntitle: Alpha\n---\nAlpha body", 1_000);
    $this->adapter->seed("$path/b.md", "---\ntitle: Beta\n---\nBeta body", 2_000);

    PaperQueryBuilder::fake(Post::class, $this->adapter);
});

it('reads the file only once the body is touched', function (): void {
    Post::find('a');
    $this->adapter->reset();

    $post = Post::find('a');
    $beforeTouch = $this->adapter->counts['read'];

    expect($beforeTouch)->toBe(0)
        ->and($post->content)->toBe('Alpha body')
        ->and($this->adapter->counts['read'])->toBe(1);
});

it('does not report a body it just read as a change', function (): void {
    $post = Post::find('a');

    expect($post->content)->toBe('Alpha body')
        ->and($post->isDirty())->toBeFalse();
});

it('keeps an untouched body in the file when the model is saved', function (): void {
    $post = Post::find('a');
    $post->title = 'Renamed';
    $post->save();

    $saved = Post::find('a');

    expect($saved->content)->toBe('Alpha body')
        ->and($saved->title)->toBe('Renamed');
});

it('filters on the body', function (): void {
    $matched = Post::whereLike('content', '%Alpha%')->get();

    expect($matched->pluck('slug')->all())->toBe(['a']);
});

it('plucks bodies rather than nulls', function (): void {
    expect(Post::pluck('content')->all())->toBe(['Alpha body', 'Beta body']);
});

it('includes the body in the model array', function (): void {
    expect(Post::find('a')->toArray())->toHaveKey('content', 'Alpha body');
});

it('leaves a content field alone for a format without a body', function (): void {
    $path = PaperQueryBuilder::contentPathFor(Page::class);

    $adapter = new CountingAdapter;
    $adapter->seed("$path/home.json", '{"content":"Home body"}', 1_000);

    PaperQueryBuilder::fake(Page::class, $adapter);

    Page::find('home');
    $adapter->reset();

    expect(Page::find('home')->content)->toBe('Home body')
        ->and($adapter->counts['read'])->toBe(0);
});
