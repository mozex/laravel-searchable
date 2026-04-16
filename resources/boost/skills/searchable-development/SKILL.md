---
name: searchable-development
description: Add multi-column database search to Eloquent models using mozex/laravel-searchable. Activate when the user mentions Searchable trait, searchableColumns, advancedSearchable, SearchableGlobalSearchProvider, multi-column search, relation search, morph search, cross-database search, or uses ->search() on Eloquent queries. Also activate when adding search to a model, configuring Filament table search, or setting up Filament global search with this package. Covers column notation (direct, relation, morph, external), column filtering (in/include/except), and Laravel Scout coexistence.
---

# Searchable Development

## When to use this skill

Activate when the user works with `mozex/laravel-searchable`, the `Searchable` trait, `searchableColumns()`, `->search()` scope, `advancedSearchable()` Filament macro, or `SearchableGlobalSearchProvider`. Also activate when adding search to an Eloquent model that uses this package.

## The Searchable Trait

Add `Mozex\Searchable\Searchable` to any Eloquent model and define `searchableColumns()`:

```php
use Mozex\Searchable\Searchable;

class Post extends Model
{
    use Searchable;

    public function searchableColumns(): array
    {
        return ['title', 'body', 'author.name', 'category.name'];
    }
}
```

Call `->search()` on any query builder:

```php
Post::query()->search('term')->get();
Post::query()->where('published', true)->search($query)->paginate();
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
->search('term', in: ['title', 'body'])

// Add columns to searchableColumns
->search('term', include: ['slug'])

// Remove columns from searchableColumns
->search('term', except: ['author.name'])

// All accept string or array
->search('term', in: 'title')
```

## Filament Integration

### Table Column Macro

When Filament is installed, `advancedSearchable()` is available on `TextColumn`. Add it to ONE column; it searches all configured `searchableColumns()`:

```php
TextColumn::make('title')
    ->advancedSearchable()
    ->sortable(),

// With filtering
TextColumn::make('title')
    ->advancedSearchable(except: ['author.name']),

// Custom scope method name
TextColumn::make('title')
    ->advancedSearchable(method: 'databaseSearch'),
```

### Global Search Provider

```php
use Mozex\Searchable\Filament\SearchableGlobalSearchProvider;

return $panel
    ->globalSearch(SearchableGlobalSearchProvider::class);
```

Auto-uses `searchableColumns()` for resources whose model has the `Searchable` trait. Falls back to Filament's default for resources without it.

## Laravel Scout Coexistence

No conflicts. Scout adds a static `Post::search()` method; this package adds a query scope `Post::query()->search()`. They're different call paths.

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
