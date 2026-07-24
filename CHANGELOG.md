# Changelog

## Unreleased
* Added `config/paper.php` to put the manifest on a dedicated cache store that survives `cache:clear`, and to tune the rebuild lock timing
* Added `has`, `doesntHave`, `whereHas`, `whereDoesntHave`, and `whereRelation`, plus `or` variants, to filter on relations with count constraints like `has('posts', '>=', 3)`
* Added dot-notation for `where`, `orderBy`, and aggregates to reach into nested frontmatter, e.g. `where('seo.title', 'x')`
* Added `whereDate`, `whereMonth`, `whereDay`, and `whereYear`, plus their `or` variants, to query frontmatter dates
* Added `paper.watch` to skip the per-request directory scan when the manifest is trusted; auto by default, on locally and off in production
* Added `PaperModel` interface that models using the `Paper` trait must implement, so pointing a relation at a non-Paper model is caught before runtime
* Added `getContentPath` so a model can resolve its content directory at runtime, e.g. a per-tenant root; defaults to the `#[ContentPath]` attribute
* Added `with` for eager loading relations, batching reads to avoid N+1 in loops
* Added `PaperRelation` abstract base for relation descriptors, with `BelongsToPaper` and `HasManyPaper` as concrete types exposing `getResults()` for lazy resolution and property access after eager loading
* Added `nested` to `#[ContentPath]` so a model reads subdirectories, turning `docs/guides/installation.md` into the slug `guides/installation`
* Added scoped route model binding so `/authors/{author}/posts/{post}` resolves the child through the parent's `hasManyPaper` relation and 404s when it belongs to another parent
* Added `HasManyPaper::query` returning the parent-scoped query, so a relation can be filtered before it runs
* Added `#[Disk]` attribute to point a model at any Laravel filesystem disk; default behavior (local FS) is unchanged when the attribute is absent
* Added `StorageAdapterContract` with `LocalAdapter` and `DiskAdapter` implementations so reads, writes, listing, and existence checks go through one abstraction
* Added `paper:warm`, `paper:clear`, and `paper:refresh` commands to warm, clear, and rebuild a model's manifest
* Changed `belongsToPaper` and `hasManyPaper` to return relation descriptors; call ->getResults() for direct resolution or use with() to eager load
* Changed `StorageAdapterContract::listing` to take a `$nested` flag, so custom adapters must add the third argument
* Changed `find` to match slugs case-sensitively, like `where`; a case-mismatched slug now returns null
* Changed `find` to throw `ContentPathNotFoundException` for a missing content directory, like `where`, instead of returning null
* Changed `DriverContract::parse` signature to `parse(string $contents)`; drivers no longer perform I/O, the adapter reads files. `PaperQueryBuilder` wraps format errors with the filepath via `FileParseException::inFile`
* Changed `latest` and `oldest` to default to `updated_at` and throw when the model has no `#[Timestamps]`; pass an explicit column to order without it
* Changed `PaperQueryBuilder::resolveFor` to no longer return the content path; it resolves per call via `getContentPath` so it can vary at runtime
* Improved `where`, `get`, and the relation descriptors to keep the model type, so `Post::where('draft', true)->get()` is a collection of `Post`
* Improved the manifest so concurrent requests on a cold cache rebuild it once, instead of each reading every file
* Optimized `where` to filter on the raw record and build only the matching models when the filtered columns have no cast, accessor, or relation
* Optimized queries to reconcile a per content-path manifest against one directory listing, so a query reads one listing instead of a metadata call per file and warm reads scale flat with file count
* Moved driver and content path resolution to PaperQueryBuilder as a single shared cache
* Removed `FileParseException::unreadable` since drivers no longer perform I/O
* Removed `UnsupportedRouteBindingException` now that `resolveChildRouteBinding` resolves the child instead of throwing

## Version 1.14.0 (2026-07-22)
* Improved the `laravel-paper-development` skill to cover lazy loading, route model binding, and driver extension order
* Optimized queries to list the content directory once instead of once per driver extension
* Fixed `#[Driver]` and `#[ContentPath]` to resolve on first use instead of when the model is instantiated

## Version 1.13.0 (2026-06-17)
* Added a benchmark suite (`composer bench`) measuring query performance across directory sizes
* Added `chunk` and `each` to process records in batches over the lazy iterator
* Added `firstOrNew` to return the first matching record or a new unsaved instance
* Added `findOr` and `firstOr` to run a callback when no record matches
* Added a key argument to `pluck` to key the results by a second column
* Improved `PaperRule` exists and unique messages to use Laravel's `validation` translation lines so they respect the app locale
* Improved unordered queries to return records in a stable slug order
* Optimized `paginate` and `simplePaginate` to read only the current page's files when the query has no `where` clause and isn't ordered by a frontmatter field
* Optimized `updated_at` to read each content file's modification time once instead of twice
* Fixed `whereNotBetween` to exclude records whose column is missing, matching `whereNotIn`

## Version 1.12.0 (2026-06-13)
* Added `min`, `max`, `sum`, `avg`, and `average` aggregate methods, skipping null and non-numeric values like SQL aggregates skip NULL
* Added route model binding so `{model}` and `{model:field}` resolve the matching record; scoped child bindings now throw `UnsupportedRouteBindingException`
* Added Laravel Boost skill `laravel-paper-development` to give AI agents Paper-specific guidance
* Optimized queries to read each content file's modification time once instead of twice
* Fixed multi-column `orderBy` to treat the first column as primary and later columns as tiebreakers, matching Eloquent
* Fixed queries returning a duplicate record when one slug exists under multiple driver extensions
* Fixed `whereIn` and `whereNotIn` to compare loosely like `where`, so `'1'` matches an integer `1` read from frontmatter

## Version 1.11.0 (2026-06-04)
* Added `#[Timestamps]` attribute to expose a model's file modification time as `updated_at`; `created_at` stays a frontmatter field
* Added `retrieved` model event, fired for each model a query returns and skipped on `count`, `exists`, `pluck`, and bulk `delete`
* Added bulk `update` to set values across all matching records
* Added `findMany` for loading multiple records by slug in one call
* Added `orderByDesc` as a descending order shortcut
* Improved `FileModificationCache` with an in-process memo to avoid repeated cache lookups within the same request
* Improved `MarkdownDriver` serialization to keep nested frontmatter in block style for cleaner diffs
* Fixed `MarkdownDriver` to omit the frontmatter block for content-only models instead of writing an empty `{  }` block

## Version 1.10.0 (2026-05-22)
* Added support for query scopes declared with Laravel's `#[Scope]` attribute, including protected methods
* Added support for the `#[CollectedBy]` attribute so queries return the model's custom collection
* Added `create` for saving a new record from an attribute array
* Added `firstOrCreate` to return the first matching record or create it
* Added `updateOrCreate` to update the first matching record or create it
* Added `saveQuietly` to persist a record without firing model events
* Added `deleteQuietly` to delete a record without firing model events
* Fixed `array`, `json`, `object`, and `collection` casts so they read from and write to flat files as native structures

## Version 1.9.0 (2026-05-20)
* Added `DriverRegistry` so custom drivers can be registered with `register()`
* Added `PaperException` interface implemented by all package exceptions
* Added a column argument to `PaperUniqueRule::ignore` for excluding a record by a column other than the slug
* Added `simplePaginate` to paginate without counting every record, reading only the rows the page needs
* Added `inRandomOrder` for returning records in random order
* Added `value` for reading a single column from the first match
* Added `firstWhere` to fetch the first record matching a where condition
* Added `when` for applying query clauses conditionally
* Added `whereAny`, `orWhereAny`, `whereAll`, and `orWhereAll` for matching a value across multiple columns
* Added `whereLike` and `orWhereLike` with an optional case-sensitive flag, matching Laravel semantics
* Fixed `delete` to mark the model as no longer existing, matching Eloquent
* Fixed path traversal in `find`, `save`, and `delete` by validating the slug is a single safe filename segment
* Fixed `save` to accept "0" as a slug instead of rejecting it as empty
* Fixed `save` to create the content directory when it is missing instead of failing silently
* Fixed `save` to overwrite the existing file's extension on update instead of writing a duplicate `.md` next to a `.markdown`
* Fixed `save` to sync model state so `isDirty`, `wasChanged`, and `wasRecentlyCreated` are correct afterward

## Version 1.8.0 (2026-04-23)
* Documented ignored Eloquent-parity parameters on `all`, `find`, `findOrFail`, and `fresh`
* Documented that `lazy` lists files up front
* Documented that `hasManyPaper` reads every related file on each call
* Fixed file discovery for drivers with multiple extensions on musl libc (Alpine) containers
* Fixed `where` comparison operators to exclude null fields, matching SQL semantics
* Fixed `save` to write atomically via temp file + rename
* Fixed `paginate` to resolve current page and path via `Paginator`

## Version 1.7.0 (2026-04-08)
* Added `exists` and `doesntExist` static methods to the Paper trait
* Optimized `count` to skip file parsing when no where clauses are applied
* Optimized `exists` and `doesntExist` to short-circuit on first match

## Version 1.6.0 (2026-04-01)
* Added `sole` query method for retrieving exactly one record
* Optimized `first` to use early termination on unordered queries
* Fixed `lazy` to pass callable instead of instantiated generator

## Version 1.5.0 (2026-03-27)
* Added scope support via `__call` on `PaperQueryBuilder`
* Added `belongsToPaper` for O(1) relationship lookups
* Added `hasManyPaper` for one-to-many relationships

## Version 1.4.0 (2026-03-19)
* Added `lazy` method for memory-efficient iteration with `LazyCollection`
* Added `PaperRule` validation with `exists` and `unique` rules for Paper models
* Added `fresh` and `refresh` methods for reloading models from file
* Optimized cache layer to eliminate redundant lookups

## Version 1.3.0 (2026-03-19)
* Added bulk `delete` on query builder
* Added `exists` and `doesntExist` query methods

## Version 1.2.1 (2026-03-19)
* Fixed two-argument `where` with string values not working correctly

## Version 1.2.0 (2026-03-19)
* Added `where(closure)` and `orWhere(closure)` for grouped conditions
* Added `whereContains` and `orWhereContains` for array column filtering
* Added `whereNull`, `orWhereNull`, `whereNotNull` and `orWhereNotNull` for null checks
* Added `whereBetween`, `orWhereBetween`, `whereNotBetween` and `orWhereNotBetween` for range queries
* Added `latest` and `oldest` ordering shortcuts
* Added `firstOrFail` query method
* Added `paginate` for pagination support

## Version 1.1.0 (2026-03-19)
* Added `count`, `pluck`, `orWhere`, `whereIn`, `orWhereIn`, `whereNotIn` and `orWhereNotIn` query methods
* Added `save` and `delete` methods for write support
* Added model events (`creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting` and `deleted`)

## Version 1.0.0 (2026-03-18)
* Initial release
