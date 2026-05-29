# Laravel Paper

[![Latest Version](https://img.shields.io/packagist/v/jacobjoergensen/laravel-paper.svg)](https://packagist.org/packages/jacobjoergensen/laravel-paper)
[![Tests](https://github.com/JacobJoergensen/laravel-paper/actions/workflows/tests.yml/badge.svg)](https://github.com/JacobJoergensen/laravel-paper/actions)
[![License](https://img.shields.io/github/license/JacobJoergensen/laravel-paper)](LICENSE)

Laravel Paper is a Laravel package that adds flat-file driver support for Eloquent. It supports Markdown and JSON files and works with Laravel 12+ on PHP 8.4+.

## Why Laravel Paper?

Two PHP 8 attributes and a trait. No custom database connection, no schema, your flat files use Eloquent's familiar query API.

## Get Started

```sh
composer require jacobjoergensen/laravel-paper
```

## Defining a Model

Put files in a content directory and point a model at it:

```php
use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('markdown')]
#[ContentPath('content/posts')]
class Post extends Model
{
    use Paper;
}
```

The filename without extension becomes the slug, which is the primary key.

## Markdown Example

A post:

```markdown
---
title: Building a Blog with Flat Files
published: true
date: 2024-03-15
tags: [laravel, markdown]
---

Your Markdown content goes here...
```

Query it like any other Eloquent model:

```php
// Get all published posts
$posts = Post::where('published', true)
    ->orderBy('date', 'desc')
    ->get();

// Find by slug
$post = Post::where('slug', 'flat-file-blog')->first();

// Filter by tag (whereContains checks membership of an array field)
$laravelPosts = Post::whereContains('tags', 'laravel')->get();

// Match a substring in a string field
$intro = Post::whereLike('title', '%hello%')->get();

// Search a value across multiple columns
$results = Post::whereAny(['title', 'content'], 'like', '%flat-file%')->get();
```

Use it in your views:

```blade
@foreach($posts as $post)
    <article>
        <h2>{{ $post->title }}</h2>
        <time>{{ $post->date }}</time>
        <div>{!! Str::markdown($post->content) !!}</div>
    </article>
@endforeach
```

## JSON Files

Works the same way with JSON:

```json
{
    "name": "Jacob Jørgensen",
    "role": "Developer",
    "github": "jacobjoergensen"
}
```

```php
#[Driver('json')]
#[ContentPath('content/team')]
class TeamMember extends Model
{
    use Paper;
}
```

```php
$team = TeamMember::all();
$devs = TeamMember::where('role', 'Developer')->get();
```

## File Naming and Slugs

The filename (without extension) is the slug:

```
content/posts/
├── hello-world.md        → slug: "hello-world"
├── my-second-post.md     → slug: "my-second-post"
└── draft-post.md         → slug: "draft-post"
```

```php
$post = Post::find('hello-world');
```

To change a slug, rename the file. For a URL that differs from the filename, add a frontmatter field and route on that instead:

```yaml
---
title: Hello World
permalink: /blog/2024/hello-world
---
```

## Writing

Paper models save and delete files using the standard Eloquent API:

```php
$post = new Post();
$post->slug = 'hello-world';
$post->title = 'Hello World';
$post->content = 'My first post.';
$post->save();

$post->title = 'Updated title';
$post->save();

$post->delete();
```

Save and delete fire the usual model events.

For attribute-array creation:

```php
Post::create([
    'slug' => 'hello-world',
    'title' => 'Hello World',
]);

Post::firstOrCreate(
    ['slug' => 'hello-world'],
    ['title' => 'Hello World'],
);

Post::updateOrCreate(
    ['slug' => 'hello-world'],
    ['title' => 'Updated title'],
);
```

To save or delete without firing events:

```php
$post->saveQuietly();
$post->deleteQuietly();
```

To reload from disk, `fresh()` returns a new instance and `refresh()` updates the current one in place:

```php
$fresh = $post->fresh();
$post->refresh();
```

## Pagination

```php
$posts = Post::paginate(15);
$posts = Post::simplePaginate(15);
```

Use `simplePaginate` for large directories where the count is expensive, and you don't need a total.

## Relationships

For relationships, use `belongsToPaper` and `hasManyPaper`:

```php
class Post extends Model
{
    use Paper;

    public function author()
    {
        return $this->belongsToPaper(Author::class);
    }
}

class Author extends Model
{
    use Paper;

    public function posts()
    {
        return $this->hasManyPaper(Post::class);
    }
}
```

```php
$post = Post::find('hello-world');
$author = $post->author();

$author = Author::find('jane-doe');
$posts = $author->posts();
```

Call these as methods, not properties. Foreign keys default to `{model}_slug` (e.g. `author_slug`). Pass a second argument to override.

## Validation

Use `PaperRule` with Laravel's validator:

```php
use JacobJoergensen\LaravelPaper\Rules\PaperRule;

$request->validate([
    'slug' => ['required', PaperRule::unique(Post::class)],
    'author_slug' => ['required', PaperRule::exists(Author::class)],
]);
```

To skip the current record on update:

```php
PaperRule::unique(Post::class)->ignore($post->slug);
```

## Custom Drivers

Markdown and JSON ship by default. To support another format, implement `DriverContract` and register it in a service provider:

```php
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;

final class YamlDriver implements DriverContract
{
    public function extensions(): array
    {
        return ['yaml', 'yml'];
    }

    public function parse(string $filepath): array
    {
        // return the file's data as an array
    }

    public function serialize(array $data): string
    {
        // return the file contents to write
    }
}
```

```php
public function boot(): void
{
    app(DriverRegistry::class)->register('yaml', YamlDriver::class);
}
```

Then point a model at it with `#[Driver('yaml')]`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for filing bugs and submitting PRs.

## License

MIT. See [LICENSE](LICENSE.md).
