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
    RelevanceSort.php                   # global hook: auto-ranks tables while searching
```

### Search column types

The trait groups searchable columns into five types, processed in this order:

1. **Direct** - plain column names (`'title'`, `'email'`)
2. **Relation** - dot notation for same-connection relations (`'user.name'`, `'posts.title'`)
3. **Morph** - colon+dot for polymorphic relations (`'commentable:post.title'`)
4. **External** - BelongsTo on a different database connection (`'product.name'` where Product uses another connection)
5. **External morph** - morph to a model on a different connection (`'commentable:product.name'`)

External searches use `whereIn` with a subquery (capped at 50 results) because cross-database JOINs aren't possible. Nested morph relations work too (`'commentable:post.user.name'`).

### Relevance ordering

`scopeSearch`/`applySearch` accept `orderByRelevance` (default `true`). After building the `WHERE`, the trait adds one `ORDER BY` key per searchable column, **in declared array order**. This is lexicographic: column 0 is the primary sort key, column 1 breaks ties, and so on - so a match in an earlier column always outranks a match found only in a later column (the user's "title beats description" requirement), with no weight/overflow math.

Each `ORDER BY` key is a graded match score, highest first:

- **Direct columns** - inline `CASE`: `3` exact, `2` prefix (`term%`), `1` substring (`%term%`), `0` no match. So exact/prefix matches float above buried substring matches.
- **Relation columns** (same connection) - a correlated subquery built via `relation.getRelationExistenceQuery(...)` (the same machinery `whereHas` uses) selecting `COALESCE(MAX(<graded CASE>), 0)`. `MAX` means a HasMany row is scored by its best-matching child.
- **Morph columns** (direct target column) - `CASE WHEN <type col> = ? THEN (<correlated MAX(CASE)> subquery) ELSE 0 END`.
- **Nested morph** (`commentable:post.author.name`), **external**, and **external morph** - binary `1`/`0` score (matched / not). External reuses the same capped `pluck` of matching keys as the `WHERE` and scores rows by `foreign_key IN (...)`; cross-database/two-hop scoring can't be graded in one SQL statement, but column-priority ordering still holds.

The ordering is added to the **outer** query (not inside the `WHERE` closure). Binding groups (`where` vs `order`) keep the subquery placeholders in the right SQL position. It does not change which rows return (a `CASE` returns `0`, never filters), so it's safe for existing count-based tests. A user `orderBy` applied *before* `->search()` stays the primary sort; relevance becomes the tiebreaker.

`applyRelevanceSort($query, $search, ...)` exposes the ordering by itself (resolve columns + `applyRelevanceOrder`, no `WHERE`), for callers that filter separately.

### Filament ordering

Relevance ordering can **not** ride the `advancedSearchable()` table macro. Filament runs the column search callback inside `$query->where(fn ($q) => ...)` (`InteractsWithTableQuery::applySearchConstraint`), and Eloquent merges only the nested `WHERE` conditions, discarding any `orderBy`. So the macro passes `orderByRelevance: false` and builds the filter only. Direct `->search()` and the global search provider (which calls `applySearch` on the outer query) are unaffected and rank correctly.

Filament **tables rank automatically** via `Filament\RelevanceSort`, registered once in `packageBooted` (guarded by `class_exists(Table::class)`):

- `register()` calls `Table::configureUsing(fn (Table $table) => $table->modifyQueryUsing(self::scope(...)))`. `configureUsing` runs in `Table::make()` for **every** table and only *appends* a query scope, so it never clobbers a table's own `defaultSort` (which the user sets later in `table()`). The scoped `ComponentManager` is a clone of the base, so the boot-time registration carries into panels.
- `scope()` runs as a `modifyQueryUsing` callback inside `HasQuery::getQuery()` (Filament injects `$livewire`). That's on the **outer** query, **before** search and sort, so the relevance keys land first and lead. It delegates to `apply()`.
- `apply($query, $search, $sortColumn, ...)` orders only when: not resolving a single record, a search term is present, no column sort is selected (`getTableSortColumn()` is `null` until the user clicks - a configured `defaultSort` does *not* set it), and the model uses `Searchable`. So relevance leads while searching, the table's own `defaultSort` becomes the tiebreaker, an explicit column sort wins, and a non-searching table is untouched. `apply()` is separate from `scope()` so the decision is testable without a live table.
- Binding groups (`where` vs `order`) stay correctly positioned even though the `orderBy` is added before the search `WHERE` - Laravel concatenates bindings by clause, not call order.
- Global opt-out: `RelevanceSort::$enabled = false`.

The automatic table behavior is verified end-to-end against a **real Filament table**, not just units: `workbench/app/Livewire/` has `TestTableComponent` (base implementing `HasActions`/`HasSchemas`/`HasTable`), `PostsTable` (Searchable model, `advancedSearchable()` column, optional `defaultSort`), and `TagsTable` (non-Searchable model). `tests/Filament/RelevanceSortTableTest.php` drives them with `Livewire::test(...)->searchTable(...)->sortTable(...)` and asserts the order of `getTableRecords()` - which runs the real `getFilteredSortedTableQuery` pipeline (our scope -> search -> sort). It covers: ranks while searching, relevance leads with the table's own `defaultSort` as tiebreaker, an explicit column sort suppresses relevance, no search leaves `defaultSort` untouched, and a non-Searchable table is unaffected. Two harness notes: `TestCase::defineEnvironment` sets `app.key` (Livewire signs its render snapshot), and `TestTableComponent::getErrorBag()` returns an empty bag (headless renders skip the middleware that seeds it).

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
- Tests cover all five search types, relevance ordering (column priority, match-quality tiers, per-type, and the on/off toggle), column resolution (`in`/`include`/`except`), empty search handling, query integration, `applySearch` for Builder conflicts, Filament macro registration, and the automatic Filament relevance sort driven through a **real Livewire table** (see the Filament ordering section above)

## Adding features

1. Add the feature to `src/Searchable.php`
2. Add test models/migrations to `workbench/` if new relation types are needed
3. Write tests in `tests/SearchableTest.php`
4. Run `composer test` (lint + phpstan + type-coverage + pest)
