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
2. **Relation** - dot notation for same-connection relations (`'user.name'`, `'posts.title'`), at any depth (`'author.company.name'`)
3. **Morph** - colon+dot for polymorphic relations (`'commentable:post.title'`)
4. **External** - BelongsTo on a different database connection (`'product.name'` where Product uses another connection)
5. **External morph** - morph to a model on a different connection (`'commentable:product.name'`)

External searches use `whereIn` with a subquery (capped at 50 results) because cross-database JOINs aren't possible. Nested morph relations work too (`'commentable:post.user.name'`).

`splitRelationColumn` splits at the **last** dot: the final segment is the column, everything before it is the relation path. Eloquent's `whereHas` reads a dotted path natively, so `'author.company.name'` needs no special handling in the `WHERE` - it becomes `whereHas('author.company', ...)`. Two-segment columns split exactly as they always did, so this changed nothing for them. External detection (`isExternalRelation`) deliberately returns `false` for a nested path: there's no single local foreign key to match a `whereIn` against, so a nested path with an external first hop falls through to regular relation search and fails loudly at the SQL level, matching how HasMany-on-another-connection already behaved.

### Search terms

`parseSearchTerms` trims the input and splits it on whitespace into terms that must **all** match (AND of ORs - each term is free to match a different column). A double-quoted run stays one term, so phrases survive. `maxTerms` (default 10) caps the split so a pasted paragraph can't expand into hundreds of predicates; `maxTerms: 1` is the escape hatch back to pure phrase matching.

One term short-circuits the split entirely and produces the same query shape as before this existed - the same flat `OR` group, one `exists` per relation column. That matters: single-word search is the common case and must not get slower. A trimmed-empty search is treated as no search at all (previously `'   '` ran a real query for `%   %` and matched nothing).

LIKE wildcards in the term are escaped by `escapeLikeWildcards` (`%`, `_`, and `!` itself) against a `ESCAPE '!'` clause. `!` is used rather than the conventional backslash because it needs no escaping inside a SQL string literal, so one clause is valid on MySQL, PostgreSQL, SQLite and SQL Server alike. **The escaping has to happen in both `likeFragment` and `relevanceCase`** - they build their `LIKE` patterns independently, and escaping one but not the other yields rows that match the `WHERE` but score `0`, silently losing the ranking.

### Relevance ordering

`scopeSearch`/`applySearch` accept `orderByRelevance` (default `true`). After building the `WHERE`, the trait adds one `ORDER BY` key per searchable column, **in declared array order**. This is lexicographic: column 0 is the primary sort key, column 1 breaks ties, and so on - so a match in an earlier column always outranks a match found only in a later column (the user's "title beats description" requirement), with no weight/overflow math.

Each `ORDER BY` key is a graded match score, highest first:

- **Direct columns** - inline `CASE`: `3` exact, `2` prefix (`term%`), `1` substring (`%term%`), `0` no match. So exact/prefix matches float above buried substring matches.
- **Relation columns** (same connection, single hop) - a correlated subquery built via `relation.getRelationExistenceQuery(...)` (the same machinery `whereHas` uses) selecting `COALESCE(MAX(<graded CASE>), 0)`. `MAX` means a HasMany row is scored by its best-matching child.
- **Morph columns** (direct target column) - `CASE WHEN <type col> = ? THEN (<correlated MAX(CASE)> subquery) ELSE 0 END`.
- **Nested relation** (`author.company.name`), **nested morph** (`commentable:post.author.name`), **external**, and **external morph** - binary `1`/`0` score (matched / not). External reuses the keys already fetched for the `WHERE` and scores rows by `foreign_key IN (...)`; cross-database/two-hop scoring can't be graded in one SQL statement, but column-priority ordering still holds.

With **multiple terms**, `gradedRelevanceScore` sums a graded `CASE` per term and adds one for the whole phrase, scaled by `3 * count($terms) + 1` - one more than the maximum the per-term scores can reach, so a contiguous phrase match provably outranks every combination of scattered term matches without overflowing. (This is the one place weight math exists; it's bounded and proven, unlike per-column weights, which remain deliberately absent.) The binary column types instead score `1` only when the column matches **every** term, which keeps them at **one** correlated subquery no matter how many terms there are. Net effect: the `ORDER BY` cost is constant in the number of terms; only the `WHERE` grows with it.

The ordering is added to the **outer** query (not inside the `WHERE` closure). Binding groups (`where` vs `order`) keep the subquery placeholders in the right SQL position. It does not change which rows return (a `CASE` returns `0`, never filters), so it's safe for existing count-based tests. A user `orderBy` applied *before* `->search()` stays the primary sort; relevance becomes the tiebreaker.

`applyRelevanceSort($query, $search, ...)` exposes the ordering by itself (resolve columns + `applyRelevanceOrder`, no `WHERE`), for callers that filter separately.

### Filament ordering

Relevance ordering can **not** ride the `advancedSearchable()` table macro. Filament runs the column search callback inside `$query->where(fn ($q) => ...)` (`InteractsWithTableQuery::applySearchConstraint`), and Eloquent merges only the nested `WHERE` conditions, discarding any `orderBy`. So the macro passes `orderByRelevance: false` and builds the filter only. Direct `->search()` and the global search provider (which calls `applySearch` on the outer query) are unaffected and rank correctly.

Filament **tables rank automatically** via `Filament\RelevanceSort`, registered once in `packageBooted` (guarded by `class_exists(Table::class)`):

- `register()` calls `Table::configureUsing(fn (Table $table) => $table->modifyQueryUsing(self::scope(...)))`. `configureUsing` runs in `Table::make()` for **every** table and only *appends* a query scope, so it never clobbers a table's own `defaultSort` (which the user sets later in `table()`). The scoped `ComponentManager` is a clone of the base, so the boot-time registration carries into panels.
- `scope()` runs as a `modifyQueryUsing` callback inside `HasQuery::getQuery()` (Filament injects `$livewire`). That's on the **outer** query, **before** search and sort, so the relevance keys land first and lead. It delegates to `apply()`.
- `apply($query, $search, $sortColumn, ...)` orders only when: not resolving a single record, a search term is present, no column sort is selected (`getTableSortColumn()` is `null` until the user clicks - a configured `defaultSort` does *not* set it), and the model uses `Searchable`. So relevance leads while searching, the table's own `defaultSort` becomes the tiebreaker, an explicit column sort wins, and a non-searching table is untouched. `apply()` is separate from `scope()` so the decision is testable without a live table.
- Binding groups (`where` vs `order`) stay correctly positioned even though the `orderBy` is added before the search `WHERE` - Laravel concatenates bindings by clause, not call order.
- The macro and `RelevanceSort` are independent call paths, so a non-default `maxTerms` (or `externalLimit`) passed to `advancedSearchable()` does **not** reach the ordering, which uses its own defaults. Harmless in practice: a scoring `CASE` never filters, so a term-count mismatch only shifts ranking, never which rows return. Pass the same value to `RelevanceSort::apply()` if you're wiring it manually and care.
- Because they're separate calls, each runs its own `resolveExternalKeys`. **The one-lookup-per-term saving applies to a direct `->search()` only**; a Filament table with an external column still hits the other connection twice per term (once for the macro's `WHERE`, once for `RelevanceSort`'s `ORDER BY`), the same as before. Verified, not assumed. Sharing the map across the two would mean threading state between two independent Filament hooks, which isn't worth the coupling.
- Global opt-out: `RelevanceSort::$enabled = false`.

The automatic table behavior is verified end-to-end against a **real Filament table**, not just units: `workbench/app/Livewire/` has `TestTableComponent` (base implementing `HasActions`/`HasSchemas`/`HasTable`), `PostsTable` (Searchable model, `advancedSearchable()` column, optional `defaultSort`), and `TagsTable` (non-Searchable model). `tests/Filament/RelevanceSortTableTest.php` drives them with `Livewire::test(...)->searchTable(...)->sortTable(...)` and asserts the order of `getTableRecords()` - which runs the real `getFilteredSortedTableQuery` pipeline (our scope -> search -> sort). It covers: ranks while searching, relevance leads with the table's own `defaultSort` as tiebreaker, an explicit column sort suppresses relevance, no search leaves `defaultSort` untouched, and a non-Searchable table is unaffected. Two harness notes: `TestCase::defineEnvironment` sets `app.key` (Livewire signs its render snapshot), and `TestTableComponent::getErrorBag()` returns an empty bag (headless renders skip the middleware that seeds it).

### Key design decisions

- **No mutable state on the model.** Query builder and search text are passed as parameters through every method, not stored as instance properties. This makes the trait safe for concurrent use.
- **`applySearch` for Builder conflicts.** When a model's Builder already has a `search()` method (e.g., Corcel's PostBuilder), `$query->search()` calls the Builder's method instead of the scope. The trait exposes `applySearch($query, $term, ...)` as a direct invocation that bypasses the Builder. The global search provider always uses `applySearch` to be safe.
- **Connection comparison resolves null.** When comparing database connections, `null` (meaning "default") is resolved to the actual default connection name. This prevents false positives when one model sets the connection explicitly and another relies on the default.
- **Only BelongsTo is detected as external.** HasMany/HasOne relations on different connections fall through to regular relation search, because cross-database `whereHas` would fail at the SQL level with a clear error rather than silently producing wrong results.
- **Untyped `MorphTo` in dot notation is skipped.** A column like `'commentable.title'` where `commentable` is a `MorphTo` can't resolve to a single related model (`MorphTo` extends `BelongsTo`, and an unconstrained `getRelated()` returns the parent), so it would build a subquery against the wrong table. `isMorphToColumn()` detects this and skips the column in both the `WHERE` (excluded from `groupColumnsByType`) and the relevance `ORDER BY` (`relevanceScoreFor` returns `null`), rather than emitting invalid SQL. The typed morph syntax `'commentable:post.title'` is the supported way to search a morph target.
- **Filament is a dev dependency.** The service provider checks `class_exists()` at runtime before registering macros (so end users without Filament aren't affected). Filament is in `require-dev` so PHPStan can analyze the `src/Filament/` directory and tests can exercise the macro.
- **External keys are fetched once, before the `WHERE`.** `resolveExternalKeys` runs the cross-connection lookups up front and hands the resulting key lists to both the `WHERE` and the `ORDER BY` as a plain `array<string, array>` keyed by `column."\0".term`. Previously each side ran its own `pluck`, so a single-column external search cost two round trips to the other connection; it now costs one. The map is a parameter, not a property - the trait stays stateless. `tests/SearchableTest.php` asserts the query count on the `external` connection so a regression here fails loudly.
- **A single term short-circuits the term split.** `scopeSearch` builds the flat `OR` group directly for one term instead of wrapping it in a redundant `AND` layer, so the overwhelmingly common one-word search keeps its old query shape and plan. Only the `ESCAPE '!'` clause is new, and it's parse-level with no plan cost.

## Testing

- **Framework**: Orchestra Testbench with Workbench
- **Test models**: `workbench/app/Models/` (Author, Post, Comment on default connection; Category on `external` connection)
- **Morph map**: Configured in `TestCase::setUp()` with aliases `post` and `category`
- **Two SQLite connections**: `testing` (default) and `external` (separate in-memory database)
- Tests cover all five search types, multi-hop relation paths (`author.posts.title`), LIKE wildcard escaping (in both the filter and the ordering), multi-term search (order independence, terms spanning columns, quoted phrases, `maxTerms`, whitespace-only input), relevance ordering (column priority, match-quality tiers, per-type, multi-term phrase-beats-scattered across direct/relation/morph columns, and the on/off toggle), external query counts, column resolution (`in`/`include`/`except`), empty search handling, query integration, `applySearch` for Builder conflicts, Filament macro registration, and the automatic Filament relevance sort driven through a **real Livewire table** (see the Filament ordering section above)
- **Ordering tests must assert actual result order**, not counts. A relevance `CASE` never filters, so a count-based test passes while the ranking is silently wrong - which is exactly how a binding-position bug would hide.

## Adding features

1. Add the feature to `src/Searchable.php`
2. Add test models/migrations to `workbench/` if new relation types are needed
3. Write tests in `tests/SearchableTest.php`
4. Run `composer test` (lint + phpstan + type-coverage + pest)
