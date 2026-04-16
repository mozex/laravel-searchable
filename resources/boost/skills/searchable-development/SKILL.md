---
name: searchable-development
description: Add multi-column database search to Eloquent models using mozex/laravel-searchable. Activate when the user mentions Searchable trait, searchableColumns, advancedSearchable, SearchableGlobalSearchProvider, applySearch, multi-column search, relation search, morph search, cross-database search, or uses ->search() on Eloquent queries. Also activate when adding search to a model, configuring Filament table search, or setting up Filament global search with this package. Covers column notation (direct, relation, morph, external), column filtering (in/include/except), Laravel Scout coexistence, and resolving conflicts when another package owns the search method name.
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
| Direct | `column` | `'title'` | `orWhereLike` on the column |
| Relation | `relation.column` | `'author.name'` | `orWhereHas` with `whereLike` |
| Morph | `relation:type.column` | `'commentable:post.title'` | `orWhereHasMorph` |
| External | `relation.column` (different DB) | `'product.name'` | `orWhereIn` with subquery (max 50 IDs) |
| External morph | `relation:type.column` (different DB) | `'commentable:product.name'` | type check + `whereIn` subquery |

External relations are auto-detected when the related model's `$connection` differs from the current model's. Only `BelongsTo` relations are detected as external (HasMany on a different connection falls through to regular relation search).

Nested morph relations work: `'commentable:post.author.name'` resolves the morph to Post, then follows the `author` relation.

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

## Filament Integration

### Table Column Macro

When Filament is installed, `advancedSearchable()` is available on `TextColumn`. Add it to ONE column; it searches all configured `searchableColumns()`:

```php
TextColumn::make('title')->advancedSearchable()->sortable(),

// With filtering
TextColumn::make('title')->advancedSearchable(except: ['author.name']),

// Custom scope method name
TextColumn::make('title')->advancedSearchable(method: 'databaseSearch'),
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
    return new Course()->searchableColumns();
}

// Or a subset
public static function getGloballySearchableAttributes(): array
{
    return ['title', 'author.name'];
}
```

If a resource doesn't define `getGloballySearchableAttributes()` (and has no `$recordTitleAttribute` set), Filament's default returns an empty array and the provider falls back to the model's full `searchableColumns()`. Resources whose models don't use the `Searchable` trait fall through to Filament's default global search.

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
        new Product()->applySearch($query, $term, ...$args);
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
        ...collect(new Faq()->searchableColumns())
            ->map(fn (string $column): string => 'faqs.' . $column)
            ->toArray(),
    ];
}
```

### Scoping search in Livewire/controllers

```php
$results = auth()->user()
    ->projects()
    ->search($this->search, except: ['user.name', 'user.email'])
    ->orderByDesc('updated_at')
    ->paginate();
```
