# Changelog

## Unreleased

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
