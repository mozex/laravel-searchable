# Laravel Searchable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-searchable.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mozex/laravel-searchable/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/laravel-searchable/actions?query=workflow%3ATests+branch%3Amain)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-searchable/v1)
[![License](https://img.shields.io/packagist/l/mozex/laravel-searchable?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-searchable.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)

Add a `Searchable` trait to any Eloquent model and search across multiple columns, relations, polymorphic relations, and cross-database relations with a single `->search()` call. It coexists with Laravel Scout (different call paths, no collisions) and ships with optional Filament integration for table search and global search.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/laravel-searchable/v1)**: searchable docs, version requirements, detailed changelog, and more.

## Table of Contents

- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Search Types](#search-types)
  - [Direct Columns](#direct-columns)
  - [Relation Columns](#relation-columns)
  - [Morph Relations](#morph-relations)
  - [Cross-Database Relations](#cross-database-relations)
- [Column Filtering](#column-filtering)
- [Filament Integration](#filament-integration)
  - [Global Search](#global-search)
- [Handling Conflicts](#handling-conflicts)
  - [Laravel Scout](#laravel-scout)
  - [Custom Builder Methods](#custom-builder-methods)

## Support This Project

I maintain this package along with [several other open-source PHP packages](https://mozex.dev/docs) used by thousands of developers every day.

If my packages save you time or help your business, consider [**sponsoring my work on GitHub Sponsors**](https://github.com/sponsors/mozex). Your support lets me keep these packages updated, respond to issues quickly, and ship new features.

Business sponsors get logo placement in package READMEs. [**See sponsorship tiers →**](https://github.com/sponsors/mozex)

## Installation

> **Requires [PHP 8.2+](https://php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/laravel-searchable/v1/requirements)

```bash
composer require mozex/laravel-searchable
```

That's it. No config files to publish, no migrations to run.

## Basic Usage

Add the `Searchable` trait to your model and define which columns should be searchable. You can mix direct columns, relation columns, and morph relations in the same array:

```php
use Mozex\Searchable\Searchable;

class Comment extends Model
{
    use Searchable;

    public function searchableColumns(): array
    {
        return [
            'body',                          // direct column
            'author.name',                   // BelongsTo relation
            'tags.name',                     // HasMany relation
            'commentable:post.title',        // morph relation
            'commentable:video.name',        // another morph type
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
```

Then search:

```php
// Shortest form, searches all configured columns
Comment::search('laravel')->get();

// Chain with other query constraints
Comment::query()
    ->where('published', true)
    ->search($request->input('q'))
    ->paginate();
```

The search wraps all its conditions in a `WHERE (... OR ...)` group, so it plays nicely with any existing query constraints.

## Search Types

### Direct Columns

Plain column names on the model's own table:

```php
public function searchableColumns(): array
{
    return ['title', 'body', 'slug'];
}
```

### Relation Columns

Use dot notation to search through BelongsTo and HasMany relations:

```php
public function searchableColumns(): array
{
    return [
        'title',
        'author.name',      // BelongsTo
        'author.email',     // BelongsTo, different column
        'comments.body',    // HasMany
        'tags.name',        // BelongsToMany / HasMany
    ];
}
```

### Morph Relations

For polymorphic relations, use `relation:morphType.column` notation. The morph type needs to match your morph map alias:

```php
// In a ServiceProvider:
Relation::morphMap([
    'post' => Post::class,
    'video' => Video::class,
]);
```

```php
class Comment extends Model
{
    use Searchable;

    public function searchableColumns(): array
    {
        return [
            'body',
            'commentable:post.title',        // search Post's title
            'commentable:video.name',         // search Video's name
            'commentable:post.author.name',   // nested: Post -> Author -> name
        ];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
```

Nested relations inside morph targets work too. `commentable:post.author.name` first resolves the morph to a Post, then follows the `author` relation on Post to search the author's name.

### Cross-Database Relations

If a BelongsTo relation points to a model on a different database connection, the package picks this up on its own. Since cross-database JOINs aren't possible, it runs a separate query on the external connection, fetches matching IDs (capped at 50), and uses `whereIn` on the foreign key. Nothing to configure.

Morph relations to external connections work the same way.

## Column Filtering

You can override or adjust which columns are searched per-query:

```php
// Search only specific columns (ignores searchableColumns)
Post::query()->search('term', in: ['title', 'body'])->get();

// Add extra columns on top of searchableColumns
Post::query()->search('term', include: ['slug'])->get();

// Exclude specific columns from searchableColumns
Post::query()->search('term', except: ['author.name'])->get();

// Combine them
Post::query()->search('term', include: ['slug'], except: ['body'])->get();
```

All three parameters accept a string or an array.

## Filament Integration

When Filament is installed, the package registers an `advancedSearchable()` macro on `TextColumn`. Add it to one column in your table, and it'll search across all your model's configured searchable columns:

```php
use Filament\Tables\Columns\TextColumn;

TextColumn::make('title')
    ->advancedSearchable()
    ->sortable(),
```

You can pass the same `in`, `include`, `except` parameters:

```php
TextColumn::make('title')
    ->advancedSearchable(except: ['author.name'])
    ->sortable(),
```

### Global Search

Global search needs both pieces wired up: the provider on your panel AND `getGloballySearchableAttributes()` on each resource. They work together, not as alternatives.

**Step 1: Register the provider on your panel.** This replaces Filament's default global search so the provider can run the model's search scope across all your trait-using resources.

```php
use Mozex\Searchable\Filament\SearchableGlobalSearchProvider;

return $panel
    ->id('admin')
    ->path('admin')
    ->globalSearch(SearchableGlobalSearchProvider::class);
```

**Step 2: Declare which columns each resource should search.** The provider passes whatever you return from `getGloballySearchableAttributes()` as the `in:` filter to the search scope. Return all of the model's columns to search everything, or a subset to scope global search to specific columns:

```php
use Filament\Resources\Resource;

class CourseResource extends Resource
{
    // Use all columns the model declared as searchable
    public static function getGloballySearchableAttributes(): array
    {
        return new Course()->searchableColumns();
    }
}

class PostResource extends Resource
{
    // Or limit global search to a specific subset, even though
    // the Post model has more columns in searchableColumns()
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'author.name'];
    }
}
```

If a resource doesn't override `getGloballySearchableAttributes()`, the provider falls back to the model's full `searchableColumns()`. Resources whose models don't use the `Searchable` trait fall through to Filament's default global search.

## Handling Conflicts

### Laravel Scout

Scout adds a static `search()` method on the model class (`Post::search('term')`). This package adds a query scope (`Post::query()->search('term')`). Technically, different call paths, so they don't collide.

In practice, having two `search` entry points on the same model gets confusing fast. The cleaner approach is to alias this package's scope to a different name using PHP's trait aliasing, so each search path has its own clear name:

```php
use Laravel\Scout\Searchable;
use Mozex\Searchable\Searchable as DatabaseSearchable;

class Lesson extends Model
{
    use DatabaseSearchable {
        scopeSearch as scopeDatabaseSearch;
    }
    use Searchable;

    public function searchableColumns(): array
    {
        return ['name', 'description'];
    }
}
```

Now `Lesson::search('term')` runs Scout's full-text search, and `Lesson::query()->databaseSearch('term')` runs this package's database search. No ambiguity.

For the Filament macro, pass the renamed method:

```php
TextColumn::make('name')->advancedSearchable(method: 'databaseSearch')
```

### Custom Builder Methods

Some packages define their own `search()` method on a custom Eloquent Builder (Corcel is a common example). When that happens, `$query->search()` calls the Builder's method instead of this package's scope.

For these cases, use `applySearch()` to call the scope directly:

```php
$query = Product::query();
$query->getModel()->applySearch($query, 'term');
$results = $query->get();
```

`applySearch` accepts the same parameters as the scope:

```php
$query->getModel()->applySearch($query, 'term', in: ['title', 'body']);
$query->getModel()->applySearch($query, 'term', except: ['author.name']);
```

You can also override the conflicting method in a custom Builder to delegate to `applySearch`, so the rest of your codebase still calls `$query->search()`:

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

For Filament's `advancedSearchable` macro, pass the `method` parameter to use a different scope name:

```php
TextColumn::make('title')
    ->advancedSearchable(method: 'databaseSearch')
```

The global search provider uses `applySearch` internally, so it works with any model regardless of Builder conflicts.

## Resources

Visit the [documentation site](https://mozex.dev/docs/laravel-searchable/v1) for searchable docs auto-updated from this repository.

- **[AI Integration](https://mozex.dev/docs/laravel-searchable/v1/ai-integration)**: Use this package with AI coding assistants via Context7 and Laravel Boost
- **[Requirements](https://mozex.dev/docs/laravel-searchable/v1/requirements)**: PHP, Laravel, and dependency versions
- **[Changelog](https://mozex.dev/docs/laravel-searchable/v1/changelog)**: Release history with linked pull requests and diffs
- **[Contributing](https://mozex.dev/docs/laravel-searchable/v1/contributing)**: Development setup, code quality, and PR guidelines
- **[Questions & Issues](https://mozex.dev/docs/laravel-searchable/v1/questions-and-issues)**: Bug reports, feature requests, and help
- **[Security](mailto:hello@mozex.dev)**: Report vulnerabilities directly via email

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
