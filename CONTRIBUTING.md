# Contributing

## Reporting bugs

Found a bug? [Open an issue](https://github.com/JacobJoergensen/laravel-paper/issues/new/choose) and fill in the bug report.

## Setup

Fork the repo, then install dev dependencies:

```sh
composer install
```

## Pull requests

Before opening one:

1. [File an issue](https://github.com/JacobJoergensen/laravel-paper/issues/new?template=feature.yml) first for anything beyond a small fix. Saves time if the approach won't fly.
2. Branch from `main` for bug fixes and additive features. Larger changes that touch the public API or the `Paper` trait shape belong on the `v2` branch.
3. Add a test. PRs without tests usually don't get merged. When fixing a bug, add a test that would have caught it.
4. Add a one-line entry under `## Unreleased` in `CHANGELOG.md`, matching the existing format.

Keep the diff focused. Don't bundle a fix with a refactor.

## Coding standards

Strict types everywhere, return types on every method, no magic where explicit works, no unnecessary abstractions.

Pint handles style. PHPStan runs at `max`. Type coverage is enforced at 100%. All three must pass before a PR can be merged.

## Running tests

```sh
composer test          # full: lint check, types, type-coverage, tests
composer test:unit     # Pest only
composer test:types    # PHPStan only
composer lint          # apply Pint formatting
```
