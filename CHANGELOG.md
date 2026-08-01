# Changelog

All notable changes to `laravel-searchable` will be documented in this file.

## 1.2.1 - 2026-08-01

### What's Changed

**Multi-word search.** A search string is now split on whitespace into terms that all have to match, and each term is free to match a different column. `search('Doe Jane')` finds "Jane Doe", and `search('Jane jane@acme.com')` matches when the name supplies one term and the email supplies the other. Wrap words in double quotes to keep them together as a single term. Splitting stops at 10 terms, adjustable with `maxTerms`.

**Relation columns at any depth.** `'author.company.name'` and longer paths now resolve through `whereHas`. Anything past two segments previously built a subquery against a table that was not in scope and failed with an "Unknown column" error.

**LIKE wildcards in the search term are escaped.** `%` and `_` are now matched literally instead of acting as wildcards. Searching for `_` returned every row in the table before this, which was both wrong and an easy way for a public search box to force a full scan. The escaping covers the relevance ranking as well as the filter.

**Fewer queries on cross-database columns.** Matching keys from the other connection are fetched once and shared by the filter and the ranking, so a direct `search()` makes one round trip per term where it previously made two.

On cost: a one-word search generates the same query it did in 1.1.x, and the `ORDER BY` stays at one scoring expression per column however many words are typed. Only the `WHERE` grows with the word count.

Heads up: multi-word searches return more rows than they did in 1.1.x. `search('jane doe')` used to look for the literal string `jane doe` and now looks for `jane` and `doe` separately. Single-word searches are unaffected. Pass `maxTerms: 1` to keep the old behavior.

**Full Changelog**: https://github.com/mozex/laravel-searchable/compare/1.1.1...1.2.1

## 1.1.1 - 2026-06-29

### What's Changed

**Fixed a query error on `MorphTo` relations referenced in dot notation.** A searchable column like `'commentable.title'`, where `commentable` is a `MorphTo`, can't resolve to a single related model, so the relevance ordering added in 1.1.0 built a subquery against the wrong table and threw a SQL error (`Unknown column ...`). These untyped morph columns are now skipped during both search and ordering instead of failing. To search a specific morph target, use the typed syntax (`'commentable:post.title'`).

**Full Changelog**: https://github.com/mozex/laravel-searchable/compare/1.1.0...1.1.1

## 1.1.0 - 2026-06-29

### What's Changed

**Relevance ordering for search results.** `search()` now ranks results by how well they match instead of returning them in id order. Earlier columns in `searchableColumns()` outrank later ones, and within a column an exact match beats a prefix beats a buried substring. Works across direct, relation, morph, and cross-database columns. On by default; opt out per query with `orderByRelevance: false`.

**Automatic ranking in Filament tables.** Tables now rank by relevance while searching, with no per-table setup. Your table's own `defaultSort` is untouched when not searching and becomes the tiebreaker when searching; an explicit column sort always wins. Disable globally with `RelevanceSort::$enabled = false`.

Heads up: `search()` now adds an `ORDER BY` by default, so result order changes from earlier versions. Pass `orderByRelevance: false` to keep the old behavior.

**Full Changelog**: https://github.com/mozex/laravel-searchable/compare/1.0.3...1.1.0

## 1.0.3 - 2026-06-23

### What's Changed

* fix json column case sensitivity
* Bump the github-actions group across 1 directory with 2 updates by @dependabot[bot] in https://github.com/mozex/laravel-searchable/pull/2

**Full Changelog**: https://github.com/mozex/laravel-searchable/compare/1.0.2...1.0.3

## 1.0.2 - 2026-04-18

### What's changed

* fix PHPStan conflict with mixin
* improve docs
* Add externalLimit cap for external searches

**Full Changelog**: https://github.com/mozex/laravel-searchable/compare/1.0.1...1.0.2

## 1.0.1 - 2026-04-16

### What's changed

* Add case sensitivity docs for searches

**Full Changelog**: https://github.com/mozex/laravel-searchable/compare/1.0.0...1.0.1

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
