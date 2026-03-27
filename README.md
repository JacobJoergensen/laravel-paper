# Laravel Paper
Laravel-Paper is a Laravel package that adds flat-file driver support for Eloquent in Laravel
<br> with a focus on modern features and type-safety. It supports Markdown and JSON files
<br> and works with Laravel 12 and 13 with PHP 8.4 or 8.5 .

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
slug: flat-file-blog
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

The filename (without extension) becomes the model's `id`:

```
content/posts/
├── hello-world.md        → id: "hello-world"
├── my-second-post.md     → id: "my-second-post"
└── draft-post.md         → id: "draft-post"
```

```php
$post = Post::find('hello-world');
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
