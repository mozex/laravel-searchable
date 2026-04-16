# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## What this is

`mozex/laravel-searchable` is a Laravel package that adds multi-column database search to Eloquent models. You add a `Searchable` trait to a model, define which columns to search (including relation columns, morph relations, and cross-database relations), and call `->search('term')` on any query builder.

The package also ships optional Filament integration: an `advancedSearchable()` table column macro and a `SearchableGlobalSearchProvider` for Filament's global search.

## Commands

```bash
composer test          # lint + phpstan + type-coverage + pest
composer test:unit     # pest only
composer test:types    # phpstan only
composer test:lint     # pint --test (check only)
composer lint          # pint (fix)
```

## Architecture

```
src/
  Searchable.php                        # The trait (core feature)
  SearchableServiceProvider.php         # Registers Filament macros conditionally
  Filament/
    SearchableGlobalSearchProvider.php  # Filament global search provider
```

### Search column types

The trait groups searchable columns into five types, processed in this order:

1. **Direct** - plain column names (`'title'`, `'email'`)
2. **Relation** - dot notation for same-connection relations (`'user.name'`, `'posts.title'`)
3. **Morph** - colon+dot for polymorphic relations (`'commentable:post.title'`)
4. **External** - BelongsTo on a different database connection (`'product.name'` where Product uses another connection)
5. **External morph** - morph to a model on a different connection (`'commentable:product.name'`)

External searches use `whereIn` with a subquery (capped at 50 results) because cross-database JOINs aren't possible. Nested morph relations work too (`'commentable:post.user.name'`).

### Key design decisions

- **No mutable state on the model.** Query builder and search text are passed as parameters through every method, not stored as instance properties. This makes the trait safe for concurrent use.
- **`applySearch` for Builder conflicts.** When a model's Builder already has a `search()` method (e.g., Corcel's PostBuilder), `$query->search()` calls the Builder's method instead of the scope. The trait exposes `applySearch($query, $term, ...)` as a direct invocation that bypasses the Builder. The global search provider always uses `applySearch` to be safe.
- **Connection comparison resolves null.** When comparing database connections, `null` (meaning "default") is resolved to the actual default connection name. This prevents false positives when one model sets the connection explicitly and another relies on the default.
- **Only BelongsTo is detected as external.** HasMany/HasOne relations on different connections fall through to regular relation search, because cross-database `whereHas` would fail at the SQL level with a clear error rather than silently producing wrong results.
- **Filament is a dev dependency.** The service provider checks `class_exists()` at runtime before registering macros (so end users without Filament aren't affected). Filament is in `require-dev` so PHPStan can analyze the `src/Filament/` directory and tests can exercise the macro.

## Testing

- **Framework**: Orchestra Testbench with Workbench
- **Test models**: `workbench/app/Models/` (Author, Post, Comment on default connection; Category on `external` connection)
- **Morph map**: Configured in `TestCase::setUp()` with aliases `post` and `category`
- **Two SQLite connections**: `testing` (default) and `external` (separate in-memory database)
- **29 tests** covering all five search types, column resolution (`in`/`include`/`except`), empty search handling, query integration, `applySearch` for Builder conflicts, and Filament macro registration

## Adding features

1. Add the feature to `src/Searchable.php`
2. Add test models/migrations to `workbench/` if new relation types are needed
3. Write tests in `tests/SearchableTest.php`
4. Run `composer test` (lint + phpstan + type-coverage + pest)
