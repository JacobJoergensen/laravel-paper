---
name: laravel-paper-development
description: Build and work with Laravel Paper flat-file Eloquent models, including the #[Driver] and #[ContentPath] attributes, slug-based keys, querying, writing, and relationships.
---

# Laravel Paper Development

Laravel Paper adds a flat-file driver to Eloquent. Markdown, JSON, and YAML files in a content
directory become queryable, writable Eloquent models, configured with PHP 8 attributes
instead of a database connection or migration.

## When to use this skill

Use this skill when a model extends `JacobJoergensen\LaravelPaper\PaperModel` or uses the
`Paper` trait, or when adding, querying, or writing the flat files that back those models.

For underlying Eloquent and Laravel behavior, use Boost's `search-docs` tool. This skill
only covers what Paper does differently.

## Defining a model

A Paper model extends `PaperModel` and declares its format and content directory with two
attributes.

```php
use JacobJoergensen\LaravelPaper\Attributes\ContentPath;
use JacobJoergensen\LaravelPaper\Attributes\Driver;
use JacobJoergensen\LaravelPaper\PaperModel;

#[Driver('markdown')]
#[ContentPath('content/posts')]
class Post extends PaperModel {}
```

- `markdown` frontmatter becomes attributes, the body is exposed as `content`.
- `json` and `yaml` top-level keys become attributes. `yaml` reads `.yaml` and `.yml`.
- `#[Driver]` defaults to `markdown`, `#[ContentPath]` defaults to `content`.

The body is read the first time something asks for it, so `getAttributes()` and `getOriginal()`
do not carry it until then. Property access, `toArray()`, `save()`, and `replicate()` do.

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

Subdirectories are ignored unless the model asks for them with `#[ContentPath('content/docs',
nested: true)]`. The slug is then the path below the content directory, so
`content/docs/guides/installation.md` has the slug `guides/installation`. A route binding on
such a slug needs `->where('doc', '.*')`, since the route compiler stops at `/`.

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

Global scopes work through `addGlobalScope` and `#[ScopedBy]`, and cover `find` and route
model binding too. A scope is a Closure or a class implementing `ScopeContract`, not
Eloquent's `Scope`, which Paper's builder cannot accept. Drop one with
`withoutGlobalScope`. An `or` in the query cannot widen past a scope, unlike in Eloquent.

`#[CollectedBy]` is also respected, so queries return your model's custom collection.

## Large result sets

A query lists the directory once and serves the rest from the manifest, reading only files
that are new or changed, so what costs on a large set is building a model per record rather
than touching the disk. Prefer `lazy` or `chunk` over `get` there. `count` and the aggregates
run on the manifest without building models at all.

```php
foreach (Post::query()->lazy() as $post) {
    // ...
}

Post::chunk(100, function (Collection $posts): void {
    // ...
});

$posts = Post::simplePaginate(15);
```

## Route model binding

`{post}` binds on the slug, `{post:title}` on any frontmatter field. Scoped child bindings
resolve through the parent's `hasManyPaper` relation, named after the plural of the parameter.

```php
Route::get('/posts/{post}', fn (Post $post) => $post);
Route::get('/posts/{post:title}', fn (Post $post) => $post);

Route::get('/authors/{author}/posts/{post}', fn (Author $author, Post $post) => $post)
    ->scopeBindings();
```

## Aggregates

`count`, `min`, `max`, `sum`, `avg`, and the `average` alias work on the model and the query
builder. They read through casts and ignore `orderBy`/`limit`/`offset`, like SQL.

```php
$next = Post::max('order') + 1;
$views = Post::where('published', true)->sum('views');
```

On an empty result `sum` returns `0` and the others return `null`. `sum` and `avg` skip null, missing and
non-numeric values. `min` and `max` skip only null and compare the rest with PHP's rules, so a column
mixing numbers and text can return the text.

## Casts

Eloquent casts work as usual. `array`, `json`, `object`, and `collection` read and write
native YAML or JSON structures rather than encoded strings, so files stay hand-editable.

```php
protected function casts(): array
{
    return ['tags' => 'array', 'views' => 'integer'];
}
```

```yaml
# Stored as a real list, not a JSON string.
tags: [laravel, markdown]
```

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

`firstOrNew` returns an unsaved instance when nothing matches. `findOr` and `firstOr` run a
callback instead:

```php
$post = Post::firstOrNew(['slug' => 'hello-world'], ['title' => 'Hello World']);
$post = Post::findOr('hello-world', fn () => abort(404));
```

## Timestamps

Paper models have no timestamps unless you add `#[Timestamps]`, which exposes the file's
modification time as `updated_at`.

```php
use JacobJoergensen\LaravelPaper\Attributes\Timestamps;

#[Driver('markdown')]
#[ContentPath('content/posts')]
#[Timestamps]
class Post extends PaperModel {}
```

`latest()` and `oldest()` order by `created_at` like Eloquent, which in Paper means a
frontmatter field, since nothing derives it. Pass a column for anything else, e.g.
`Post::latest('updated_at')` to order by the file mtime.

`updated_at` comes from the file's mtime and is never written to frontmatter. `created_at`
is not derived; set it as a frontmatter field if you need it. A Git checkout resets mtimes
to deploy time, so use `#[Timestamps]` for content edited in place and keep a frontmatter
`date` for Git-deployed content.

## Relationships

Read a relation as a property and it resolves on first access, like Eloquent. The method
returns a descriptor instead of the result, so `author()->getResults()` resolves it
explicitly and `with()` eager loads a whole set.

```php
use JacobJoergensen\LaravelPaper\PaperModel;
use JacobJoergensen\LaravelPaper\Relations\BelongsToPaper;
use JacobJoergensen\LaravelPaper\Relations\HasManyPaper;

class Post extends PaperModel
{
    /**
     * @return BelongsToPaper<Author>
     */
    public function author(): BelongsToPaper
    {
        return $this->belongsToPaper(Author::class);
    }
}

class Author extends PaperModel
{
    /**
     * @return HasManyPaper<Post>
     */
    public function posts(): HasManyPaper
    {
        return $this->hasManyPaper(Post::class);
    }
}

$author = Post::find('hello-world')->author;

foreach (Post::with('author')->get() as $post) {
    $post->author;
}
```

A lazy load reads the manifest rather than the file, so a loop without `with()` costs far
less than an N+1 in SQL, but `with()` still saves the repeated lookup.

Foreign keys default to `{model}_slug` (e.g. `author_slug`); pass a second argument to
override. `HasManyPaper::query()` returns the parent-scoped query when a relation needs
filtering before it runs.

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

`markdown`, `json`, and `yaml` are registered by default. To support another format, implement
`DriverContract` (`extensions`, `bodyColumn`, `parse`, `serialize`) and register it in a
service provider's `boot` method, then point a model at it with `#[Driver('toml')]`.

```php
use JacobJoergensen\LaravelPaper\Drivers\DriverRegistry;

app(DriverRegistry::class)->register('toml', TomlDriver::class);
```

`parse` takes the file contents, not a path. `bodyColumn` names the attribute the body is
exposed as, `content` for Markdown, and returns `null` for a format that is data only.

Order `extensions()` deliberately. New records are written with the first one, and when a slug
exists under several, the first one wins.
