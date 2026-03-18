# Laravel Paper
Laravel-Paper is a Laravel package that adds flat-file driver support for Eloquent in Laravel
<br> with a focus on modern features and type-safety. It supports Markdown and JSON files 
<br> and works with Laravel 12 and 13 with PHP 8.4 or 8.5 .

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
