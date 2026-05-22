# Changelog

## Unreleased
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
