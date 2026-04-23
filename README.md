# Laravel Paper

Laravel-Paper is a Laravel package that adds flat-file driver support for Eloquent. It supports Markdown and JSON files and works with Laravel 12+ on PHP 8.4+.

## Why Laravel-Paper?

Two PHP 8 attributes and a trait. No custom database connection, no schema, your flat files use Eloquent's familiar query API.

## Get Started
To get started run the following command in your project

```sh
composer require jacobjoergensen/laravel-paper
```

## Quick Example

Put your Markdown files in `content/posts/`:

```markdown
---
title: Building a Blog with Flat Files
published: true
date: 2024-03-15
tags: [laravel, markdown]
---

Your Markdown content goes here...
```

Create a new model:

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

Query it like any other Eloquent model:

```php
// Get all published posts
$posts = Post::where('published', true)
    ->orderBy('date', 'desc')
    ->get();

// Find by slug
$post = Post::where('slug', 'flat-file-blog')->first();

// Filter by tag
$laravelPosts = Post::whereContains('tags', 'laravel')->get();
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

## File Naming

The filename (without extension) becomes the model's `slug`:

```
content/posts/
├── hello-world.md        → slug: "hello-world"
├── my-second-post.md     → slug: "my-second-post"
└── draft-post.md         → slug: "draft-post"
```

```php
$post = Post::find('hello-world');
```

## Writing

Paper models save and delete files using the standard Eloquent API.

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

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
