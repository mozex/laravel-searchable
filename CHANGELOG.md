# Changelog

All notable changes to `laravel-searchable` will be documented in this file.

## 1.0.0 - 2026-04-16

### What's changed

First public release.

#### Features

- **`Searchable` trait for Eloquent models.** Add `use Mozex\Searchable\Searchable;` to a model, define `searchableColumns()`, and call `->search('term')` on any query. Conditions are wrapped in a `WHERE (... OR ...)` group so the scope composes cleanly with existing constraints.
  
- **Five column types in one array.** Mix direct columns (`'title'`), relation columns via dot notation (`'author.name'`, `'tags.name'`), morph relations via colon+dot notation (`'commentable:post.title'`), and cross-database relations — all detected automatically from the string format. Nested relations inside morph targets work too (`'commentable:post.author.name'`).
  
- **Cross-database search without configuration.** When a `BelongsTo` relation points to a model on a different connection, the package runs a separate query on that connection, fetches matching IDs (capped at 50), and uses `whereIn` on the foreign key. Same for morph relations to external connections. Nothing to wire up.
  
- **Per-query column filtering.** Pass `in: [...]` to override `searchableColumns()`, `include: [...]` to add columns on top, or `except: [...]` to remove columns — all accept a string or an array.
  
- **Filament table integration.** When Filament is installed, an `advancedSearchable()` macro is registered on `TextColumn`. Add it to one column and the table search box queries every column the model declared as searchable.
  
- **Filament global search provider.** `SearchableGlobalSearchProvider` plugs into a panel via `->globalSearch(...)` and uses each resource's `getGloballySearchableAttributes()` as the column filter. Resources whose models don't use the `Searchable` trait fall back to Filament's default global search behavior.
  
- **`applySearch()` escape hatch.** When a custom Eloquent Builder owns the `search()` name (Corcel's `PostBuilder` is the textbook case) or a parent model already declares `scopeSearch` with a different signature, `$model->applySearch($query, 'term', ...)` invokes the scope without going through the conflicting name.
  
- **Laravel Scout coexistence.** Scout adds a static `search()` method, this package adds a query scope — different call paths, no collision. For clarity, the README documents the trait-aliasing pattern (`use Searchable { scopeSearch as scopeDatabaseSearch; }`) so each entry point gets its own name.
  
- **Laravel Boost skill.** Ships at `resources/boost/skills/searchable-development/SKILL.md` and loads automatically on `php artisan boost:install`. Covers all five column types, column filtering, Filament integration, Scout coexistence, and the `applySearch` escape hatch.
  

#### Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Filament 4 or 5 (optional, only if you want the macro and global search provider)
