# Laravel Paper
Paper is a Laravel package that adds flat-file driver support for Eloquent in Laravel.
It supports <br> Markdown and JSON files and are based on Laravel 13 and PHP 8.5 with a focus 
on modern <br> features and type-safety.

## Get Started
To get started run the following command in your project 

```sh
composer require jacobjoergensen/laravel-paper
```

## Usage

### Markdown Files

```php
<?php

namespace App\Models;

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

### JSON Files

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\Paper;

#[Driver('json')]
#[ContentPath('content/authors')]
class Author extends Model
{
    use Paper;
}
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
