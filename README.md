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

> YAML reads an unquoted `date: 2024-03-15` as a Unix timestamp. Quote it or cast it with `'date' => 'date'` so comparisons like `where('date', '>', '2024-01-01')` work.

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
$posts = Post::findMany(['hello-world', 'my-second-post']);
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

Save and delete fire the usual model events, and loading a record fires `retrieved`.

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

`create` requires a `slug` and does not derive one from other fields. If your source data has no slug, generate one yourself with `Str::slug($title)`.

For bulk edits, `update` sets values across every matching record:

```php
Post::where('draft', true)->update(['published' => true]);
```

It writes each matching file in a loop, so model events fire per record and `$fillable` does not apply. It is not a single atomic operation.

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

## Timestamps

Paper models have no timestamps by default. Add `#[Timestamps]` to expose the file's modification time as `updated_at`:

```php
use JacobJoergensen\LaravelPaper\Attributes\Timestamps;

#[Driver('markdown')]
#[ContentPath('content/posts')]
#[Timestamps]
class Post extends Model
{
    use Paper;
}
```

```php
$post = Post::find('hello-world');
$post->updated_at;                          // Carbon instance from the file's mtime

$recent = Post::latest('updated_at')->get();
```

`updated_at` comes from the file's mtime and is never written to frontmatter. `created_at` isn't derived; set it in frontmatter if you need it. A Git checkout resets mtimes to the deploy time, so use this for content edited in place and keep a frontmatter `date` for Git-deployed content.

## Pagination

```php
$posts = Post::paginate(15);
$posts = Post::simplePaginate(15);
```

Use `simplePaginate` for large directories where the count is expensive, and you don't need a total.

## Aggregates

Alongside `count`, Paper has `min`, `max`, `sum`, `avg`, and its alias `average`:

```php
$next = Post::max('order') + 1;
$views = Post::where('published', true)->sum('views');
```

On an empty result `sum` returns `0` and the rest return `null`. Null, missing, and non-numeric values are ignored, the same way SQL aggregates skip `NULL`.

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

## Storage Disks

By default, Paper stores files on the local filesystem under your project's `base_path()`. To use any disk configured in `config/filesystems.php` instead, add the `#[Disk]` attribute:

```php
#[Driver('markdown')]
#[ContentPath('articles')]
#[Disk('s3')]
class Article extends Model
{
    use Paper;
}
```

With `#[Disk]` set, the content path is resolved relative to the disk root (no `base_path()` prefix). All reads, writes, and listings go through `Storage::disk(...)`.

Two caveats worth knowing before you point Paper at a remote disk:

**No atomic writes on remote disks.** The local adapter writes through a temp file and atomic rename, so a crash mid-write leaves the previous file intact. Remote disks (S3 et al.) use a single `put()` call. A failed write can leave a partial object. This is a physical limitation of remote object stores, not a bug.

**Cache staleness checks are slower on remote disks.** Paper checks file modification time on every cached read to detect changes. On local FS that's a microsecond syscall; on S3 it's a `HeadObject` API call (tens of milliseconds, billed per request). If you have hot content on a remote disk, expect noticeably more latency than the local case. Increasing the underlying Laravel cache TTL helps if your content rarely changes.

## AI-Assisted Development

Paper ships a [Laravel Boost](https://laravel.com/docs/boost) skill. If your project uses
Boost, `php artisan boost:install` offers to install it, giving your AI agent Paper-specific
guidance for writing and querying flat-file models.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for filing bugs and submitting PRs.

## License

MIT. See [LICENSE](LICENSE.md).
