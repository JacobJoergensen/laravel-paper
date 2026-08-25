# Laravel Paper

[![Latest Version](https://img.shields.io/packagist/v/jacobjoergensen/laravel-paper.svg)](https://packagist.org/packages/jacobjoergensen/laravel-paper)
[![Documentation](https://img.shields.io/badge/docs-laravel--paper.com-blue)](https://laravel-paper.com/docs/getting-started/)
[![Tests](https://github.com/JacobJoergensen/laravel-paper/actions/workflows/tests.yml/badge.svg)](https://github.com/JacobJoergensen/laravel-paper/actions)
[![License](https://img.shields.io/github/license/JacobJoergensen/laravel-paper)](LICENSE)

Laravel Paper is a Laravel package that adds flat-file driver support for Eloquent. It supports Markdown and JSON files and works with Laravel 12+ on PHP 8.4+.

## Why Laravel Paper?

Two PHP 8 attributes and a trait. No custom database connection, no schema, your flat files use Eloquent's familiar query API.

## Get Started

```sh
composer require jacobjoergensen/laravel-paper
```

Write a file in a content directory:

```markdown
---
title: Building a Blog with Flat Files
published: true
date: "2024-03-15"
---

Your Markdown content goes here...
```

Point a model at that directory:

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

The filename without extension becomes the slug, which is the primary key. From there it is Eloquent:

```php
$posts = Post::where('published', true)
    ->orderBy('date', 'desc')
    ->get();

$post = Post::find('hello-world');
```

## Documentation

Find the full documentation at [laravel-paper.com](https://laravel-paper.com/docs/getting-started/).

## AI-Assisted Development

Paper ships a [Laravel Boost](https://laravel.com/docs/boost) skill. If your project uses
Boost, `php artisan boost:install` offers to install it, giving your AI agent Paper-specific
guidance for writing and querying flat-file models.

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for filing bugs and submitting PRs.

## License

MIT. See [LICENSE](LICENSE.md).
