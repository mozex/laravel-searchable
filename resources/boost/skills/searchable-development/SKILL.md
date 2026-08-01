---
name: searchable-development
description: Add multi-column database search to Eloquent models using mozex/laravel-searchable. Activate when the user mentions Searchable trait, searchableColumns, advancedSearchable, SearchableGlobalSearchProvider, applySearch, multi-column search, relation search, nested or multi-hop relation search, morph search, cross-database search, multi-word or multi-term search, quoted phrase search, maxTerms, LIKE wildcard escaping, relevance ordering, orderByRelevance, ranking search results, or uses ->search() on Eloquent queries. Also activate when adding search to a model, configuring Filament table search, or setting up Filament global search with this package. Covers column notation (direct, relation, morph, external), column filtering (in/include/except), multi-word term splitting, relevance ordering, Laravel Scout coexistence, and resolving conflicts when another package owns the search method name.
---

# Searchable Development

## When to use this skill

Activate when the user works with `mozex/laravel-searchable`, the `Searchable` trait, `searchableColumns()`, `->search()` scope, `applySearch()`, `advancedSearchable()` Filament macro, or `SearchableGlobalSearchProvider`. Also activate when adding search to an Eloquent model that uses this package.

## The Searchable Trait

Add `Mozex\Searchable\Searchable` to any Eloquent model and define `searchableColumns()`. You can mix direct columns, relation columns, and morph relations in the same array:

```php
use Mozex\Searchable\Searchable;

class Comment extends Model
{
    use Searchable;

    public function searchableColumns(): array
    {
        return [
            'body',
            'author.name',
            'tags.name',
            'commentable:post.title',
            'commentable:video.name',
        ];
    }
}
```

Then search:

```php
// Shortest form
Comment::search('term')->get();

// Chain with other constraints
Comment::query()->where('published', true)->search('term')->paginate();
```

## Column Notation

Five column types, detected automatically from the string format:

| Type | Format | Example | How it searches |
|------|--------|---------|-----------------|
| Direct | `column` | `'title'` | case-insensitive `LIKE` on the column |
| Relation | `relation.column` | `'author.name'` | `orWhereHas` with a `LIKE` |
| Morph | `relation:type.column` | `'commentable:post.title'` | `orWhereHasMorph` |
| External | `relation.column` (different DB) | `'product.name'` | `orWhereIn` with subquery (default cap of 50 IDs) |
| External morph | `relation:type.column` (different DB) | `'commentable:product.name'` | type check + `whereIn` subquery (same cap) |

External relations are auto-detected when the related model's `$connection` differs from the current model's. Only single-hop `BelongsTo` relations are detected as external (HasMany on a different connection falls through to regular relation search).

Relation paths go as deep as you need. The LAST segment is the column, everything before it is the relation chain, so `'author.company.name'` becomes `whereHas('author.company', ...)`. Two-segment columns behave exactly as before.

Nested morph relations work too: `'commentable:post.author.name'` resolves the morph to Post, then follows the `author` relation.

A `MorphTo` MUST use the typed colon syntax. Writing it as plain dot notation (`'commentable.title'`) can't resolve to a single model, so the package skips that column instead of erroring. Use `'commentable:post.title'`.

## Multi-Word Search

A search string is split on whitespace into terms that must ALL match. Each term is its own `OR` group across the searchable columns, and the groups are joined with `AND`, so every term has to appear somewhere but each may appear in a different column.

```php
Author::search('Doe Jane')->get();          // finds "Jane Doe"; order doesn't matter
Author::search('Jane acme.com')->get();     // "Jane" in name, "acme.com" in email
```

Double quotes keep a run of words together as one term:

```php
Author::search('"Jane Doe"')->get();          // contiguous phrase only
Author::search('"Jane Doe" senior')->get();   // phrase plus a loose term
```

`maxTerms` (default 10) caps the split so a pasted paragraph can't build an unbounded query. Set it to `1` for the pre-1.2 behavior of matching the whole string as one literal phrase:

```php
Post::search('term', maxTerms: 25)->get();
Post::search('exact phrase please', maxTerms: 1)->get();
```

A one-word search short-circuits the split and generates the same query it did before this existed. A whitespace-only search is treated as no search, same as `null` or `''`.

## Wildcard Escaping

`%` and `_` in the search term are escaped before they reach SQL, against an `ESCAPE '!'` clause. So `search('_')` returns rows containing a literal underscore rather than every row in the table, and `search('100%')` finds "100% Cotton" rather than everything beginning with `100`. The escaping covers the relevance ranking as well as the filter. Nothing to configure.

## Case Sensitivity

Matching is case-insensitive by default. `Post::search('LARAVEL')` matches `laravel`, `Laravel`, and `LARAVEL` with no extra flag.

The package lowercases both operands instead of relying on the column's collation, so this holds even on binary-collated columns. That's what makes it work on the JSON columns translatable models use, which MySQL compares case-sensitively under a plain `LIKE`.

- **MySQL / MariaDB**: case-insensitive on every collation, `_bin` and `_cs` included.
- **PostgreSQL**: case-insensitive.
- **SQLite**: case-insensitive for ASCII only. SQLite's `LOWER()` leaves non-ASCII alone.

The package exposes no flag to flip this. For a case-sensitive match, add that constraint separately.

## Column Filtering Parameters

Override or adjust columns per-query:

```php
// Only search these columns (ignores searchableColumns)
Post::search('term', in: ['title', 'body'])->get();

// Add columns to searchableColumns
Post::search('term', include: ['slug'])->get();

// Remove columns from searchableColumns
Post::search('term', except: ['author.name'])->get();

// All accept string or array
Post::search('term', in: 'title')->get();
```

### Adjusting the Cross-Database Cap

External relation and external morph columns run a subquery on the other connection and feed matching IDs into a `whereIn`. The subquery caps results at 50 by default to keep the `IN (...)` clause sane. Override per-query with `externalLimit`:

```php
Post::search('term', externalLimit: 200)->get();

// Same parameter works on applySearch and the Filament macro
$model->applySearch($query, 'term', externalLimit: 200);
TextColumn::make('title')->advancedSearchable(externalLimit: 200);
```

The parameter is ignored when no external columns are involved.

## Relevance Ordering

Results are ranked by match relevance automatically. The `searchableColumns()` array order is the priority: a match in an earlier column outranks a match found only in a later column, so put your most important column (usually `title` or `name`) first. Within a column, exact matches rank above prefix matches above buried substring matches.

The grading depends on the column type:

- Direct, single-hop same-connection relation, and direct-target morph columns get full exact/prefix/substring scoring.
- HasMany relations are scored by their best-matching child row.
- Cross-database relations, multi-hop relations (`author.company.name`), and two-hop morph columns (`commentable:post.author.name`) get a matched-or-not score, but still respect column priority.

With multiple terms, a column scores each term and sums them, and a match on the whole phrase outranks any combination of individual term matches. Searching "laravel guide", a title of `Laravel Guide` ranks above `Guide to Laravel`. The matched-or-not column types score `1` only when that column contains every term.

The `ORDER BY` cost does not grow with the term count: each column still contributes one scoring expression and at most one subquery, however many words were typed. Only the `WHERE` grows.

Toggle with `orderByRelevance` (default `true`):

```php
Post::search('term', orderByRelevance: false)->get();

// Same parameter on applySearch
$model->applySearch($query, 'term', orderByRelevance: false);
```

The trait adds one `ORDER BY` key per searchable column, after the `WHERE`. An `orderBy()` added BEFORE `search()` stays the primary sort and relevance becomes the tiebreaker; added AFTER `search()`, relevance leads and your column breaks ties.

`applyRelevanceSort($query, $search, in/include/except, externalLimit)` applies just the ordering (no WHERE) for callers that filter separately.

### Filament tables: ranking is automatic

Filament tables rank by relevance automatically while searching. No per-table code. When the package boots with Filament present, `RelevanceSort::register()` adds a global table query scope (via `Table::configureUsing` + `modifyQueryUsing`) that runs on the outer query before sorting.

It composes instead of replacing:

- Not searching: does nothing, the table's own `defaultSort` runs untouched.
- Searching: relevance leads, the table's `defaultSort` becomes the tiebreaker.
- User clicks a sortable column: relevance steps aside, their sort wins.

It applies only to models that use the `Searchable` trait. Note the macro can't rank on its own (Filament runs the search callback inside a nested WHERE, and Eloquent discards any `orderBy` there), which is why ranking rides a query scope instead.

Global opt-out, then wire manually if you want:

```php
use Mozex\Searchable\Filament\RelevanceSort;

RelevanceSort::$enabled = false;

// reuse the decision logic anywhere ($search + $sortColumn from the table livewire)
RelevanceSort::apply($query, $search, $sortColumn);
```

## Filament Integration

### Table Column Macro

When Filament is installed, `advancedSearchable()` is available on `TextColumn`. Add it to ONE column; it searches all configured `searchableColumns()`:

```php
TextColumn::make('title')->advancedSearchable()->sortable(),

// With filtering
TextColumn::make('title')->advancedSearchable(except: ['author.name']),

// Custom scope method name
TextColumn::make('title')->advancedSearchable(method: 'databaseSearch'),

// Term cap and external cap
TextColumn::make('title')->advancedSearchable(externalLimit: 200, maxTerms: 5),
```

### Global Search Provider

Register the provider on the panel:

```php
use Mozex\Searchable\Filament\SearchableGlobalSearchProvider;

return $panel->globalSearch(SearchableGlobalSearchProvider::class);
```

The provider passes each resource's `getGloballySearchableAttributes()` as the `in:` filter to the model's search scope. Resources can either return all of the model's columns or a subset:

```php
// Use all searchable columns
public static function getGloballySearchableAttributes(): array
{
    return (new Course)->searchableColumns();
}

// Or a subset
public static function getGloballySearchableAttributes(): array
{
    return ['title', 'author.name'];
}
```

Each resource you want in global search MUST define `getGloballySearchableAttributes()`. Resources without it are excluded from global search entirely. Resources whose models don't use the `Searchable` trait fall through to Filament's default global search.

## Laravel Scout Coexistence

Scout adds a static `Post::search()` method; this package adds a query scope. Different call paths, so they don't collide.

In practice, having two `search` entry points on the same model is confusing. The cleaner pattern is to alias this package's scope to a different name with PHP's trait aliasing:

```php
use Laravel\Scout\Searchable;
use Mozex\Searchable\Searchable as DatabaseSearchable;

class Lesson extends Model
{
    use DatabaseSearchable {
        scopeSearch as scopeDatabaseSearch;
    }
    use Searchable;
}
```

Now `Lesson::search('term')` runs Scout, `Lesson::databaseSearch('term')` runs this package. For the Filament macro, pass the renamed method via `advancedSearchable(method: 'databaseSearch')`.

## Existing `search` Methods on Builder or Parent

Two cases where `$query->search()` won't reach this package's scope:

1. **Custom Eloquent Builder owns `search()`** (Corcel's `PostBuilder` is the textbook example). The Builder's method wins.
2. **Parent model already declares `scopeSearch`** with a different signature. PHP throws a fatal error when the trait is added because trait methods must be signature-compatible with inherited methods.

For both cases, use `applySearch()` to invoke the scope without going through the `search` name:

```php
$query = Product::query();
$query->getModel()->applySearch($query, 'term', in: ['title']);
$results = $query->get();
```

For the parent-model signature conflict, alias the scope when adding the trait (same pattern as the Scout case):

```php
use Mozex\Searchable\Searchable as DatabaseSearchable;

class Product extends VendorModel
{
    use DatabaseSearchable {
        scopeSearch as scopeDatabaseSearch;
    }
}
```

For the Builder case, you can also delegate back to `applySearch` from the Builder's `search()` so the rest of the codebase keeps calling `$query->search()`:

```php
class ProductBuilder extends \Corcel\Model\Builder\PostBuilder
{
    public function search($term = false, ...$args): self
    {
        $query = Product::query();
        (new Product)->applySearch($query, $term, ...$args);
        return $query;
    }
}
```

## Common Patterns

### Dynamic column composition from related models

```php
public function searchableColumns(): array
{
    return [
        'name',
        'slug',
        ...collect((new Faq)->searchableColumns())
            ->map(fn (string $column): string => 'faqs.' . $column)
            ->toArray(),
    ];
}
```

### Scoping search in Livewire/controllers

```php
$results = auth()->user()
    ->projects()
    ->orderByDesc('updated_at')  // primary sort; relevance breaks ties
    ->search($this->search, except: ['user.name', 'user.email'])
    ->paginate();
```

Put `orderByDesc('updated_at')` before `search()` so the recency sort stays primary. Call it after `search()` instead, and relevance leads with `updated_at` as the tiebreaker.
