---
name: laravel-paper-development
description: Build and work with Laravel Paper flat-file Eloquent models, including the #[Driver] and #[ContentPath] attributes, slug-based keys, querying, writing, and relationships.
---

# Laravel Paper Development

Laravel Paper adds a flat-file driver to Eloquent. Markdown and JSON files in a content
directory become queryable, writable Eloquent models, configured with PHP 8 attributes and
the `Paper` trait instead of a database connection or migration.

## When to use this skill

Use this skill when a model uses the `JacobJoergensen\LaravelPaper\Paper` trait, or when
adding, querying, or writing the flat files that back those models.

For underlying Eloquent and Laravel behavior, use Boost's `search-docs` tool. This skill
only covers what Paper does differently.

## Defining a model

A Paper model extends `Model`, uses the `Paper` trait, and declares its format and content
directory with two attributes.

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

`markdown` and `json` ship by default. In a Markdown file the YAML frontmatter becomes the
model's attributes and the body is exposed as `content`; in a JSON file the top-level keys
become attributes.

Both attributes are optional: a model with no `#[Driver]` uses the `markdown` driver, and
one with no `#[ContentPath]` reads from the `content` directory.

## The slug is the primary key

The filename without its extension is the slug, and the slug is the primary key. There is
no auto-incrementing id.

```
content/posts/
├── hello-world.md     → slug: "hello-world"
└── my-second-post.md  → slug: "my-second-post"
```

```php
$post = Post::find('hello-world');
$posts = Post::findMany(['hello-world', 'my-second-post']);
```

To change a slug, rename the file. For a public URL that differs from the filename, add a
frontmatter field (e.g. `permalink`) and route on that instead of the slug.

## Querying

Query Paper models with the standard Eloquent query API.

```php
$posts = Post::where('published', true)->orderBy('date', 'desc')->get();
$post = Post::where('slug', 'hello-world')->first();
```

Paper adds `whereContains` for array-field membership. It matches rows where the array
attribute includes the given value:

```php
// Posts whose `tags` frontmatter list contains "laravel"
$laravelPosts = Post::whereContains('tags', 'laravel')->get();
```

Local query scopes work, including ones declared with Laravel's `#[Scope]` attribute. The
scope receives Paper's query builder, so type-hint `PaperQueryBuilder`, not Eloquent's
`Builder`:

```php
use Illuminate\Database\Eloquent\Attributes\Scope;
use JacobJoergensen\LaravelPaper\PaperQueryBuilder;

#[Scope]
protected function published(PaperQueryBuilder $query): PaperQueryBuilder
{
    return $query->where('published', true);
}

// Call scopes through query(): Post::query()->published()->get();
```

`#[CollectedBy]` is also respected, so queries return your model's custom collection.

## Aggregates

`count`, `min`, `max`, `sum`, `avg`, and the `average` alias work on the model and the query
builder. They read through casts and ignore `orderBy`/`limit`/`offset`, like SQL.

```php
$next = Post::max('order') + 1;
$views = Post::where('published', true)->sum('views');
```

On an empty result `sum` returns `0` and the others return `null`. Null, missing, and non-numeric values are skipped.

## Casts

Eloquent `$casts` work on Paper models. The `array`, `json`, `object`, and `collection`
casts read from and write back to the file as native structures (a YAML or JSON list), not
as encoded strings, so the files stay readable and hand-editable.

## Writing

Save and delete go through the standard Eloquent API and fire the usual model events;
loading a record fires `retrieved`.

```php
$post = new Post();
$post->slug = 'hello-world';
$post->title = 'Hello World';
$post->content = 'My first post.';
$post->save();

$post->delete();
```

`create`, `firstOrCreate`, and `updateOrCreate` work from an attribute array. Like any
Eloquent model, these use mass assignment, so set `$fillable` (or `$guarded = []`) on the
model. The array must include the slug, since it is the key:

```php
Post::create(['slug' => 'hello-world', 'title' => 'Hello World']);
Post::updateOrCreate(['slug' => 'hello-world'], ['title' => 'Updated title']);
```

Bulk `update` writes each matching file in a loop. Model events fire per record, `$fillable`
does not apply, and it is not a single atomic operation:

```php
Post::where('draft', true)->update(['published' => true]);
```

Use `saveQuietly` and `deleteQuietly` to persist without firing events. Use `fresh` for a
new instance reloaded from disk, or `refresh` to reload the current one in place.

## Timestamps

Paper models have no timestamps unless you add `#[Timestamps]`, which exposes the file's
modification time as `updated_at`.

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

`updated_at` comes from the file's mtime and is never written to frontmatter. `created_at`
is not derived; set it as a frontmatter field if you need it. A Git checkout resets mtimes
to deploy time, so use `#[Timestamps]` for content edited in place and keep a frontmatter
`date` for Git-deployed content.

## Relationships

Use `belongsToPaper` and `hasManyPaper`, and call them as methods, not properties.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JacobJoergensen\LaravelPaper\Paper;

class Post extends Model
{
    use Paper;

    public function author(): ?Author
    {
        return $this->belongsToPaper(Author::class);
    }
}

class Author extends Model
{
    use Paper;

    public function posts(): Collection
    {
        return $this->hasManyPaper(Post::class);
    }
}

$author = Post::find('hello-world')->author();
$posts = Author::find('jane-doe')->posts();
```

Foreign keys default to `{model}_slug` (e.g. `author_slug`); pass a second argument to
override.

## Validation

Use `PaperRule` with Laravel's validator:

```php
use JacobJoergensen\LaravelPaper\Rules\PaperRule;

$request->validate([
    'slug' => ['required', PaperRule::unique(Post::class)],
    'author_slug' => ['required', PaperRule::exists(Author::class)],
]);

// Skip the current record when validating an update:
PaperRule::unique(Post::class)->ignore($post->slug);
```

## Custom drivers

To support another file format, implement `DriverContract` and register it in a service
provider's `boot` method, then point a model at it with `#[Driver('yaml')]`.

```php
use JacobJoergensen\LaravelPaper\Contracts\DriverContract;
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;
use Symfony\Component\Yaml\Yaml;

final readonly class YamlDriver implements DriverContract
{
    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['yaml', 'yml'];
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $filepath): array
    {
        $data = Yaml::parseFile($filepath);

        if (! is_array($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function serialize(array $data): string
    {
        return Yaml::dump($data);
    }
}

// In a service provider:
app(DriverRegistry::class)->register('yaml', YamlDriver::class);
```
