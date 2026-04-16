# Laravel Searchable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-searchable.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mozex/laravel-searchable/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/laravel-searchable/actions?query=workflow%3ATests+branch%3Amain)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-searchable/v1)
[![License](https://img.shields.io/packagist/l/mozex/laravel-searchable?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-searchable.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)

Add a `Searchable` trait to any Eloquent model and search across multiple columns, relations, polymorphic relations, and even cross-database relations with a single `->search()` call. Works alongside Laravel Scout without conflicts, and ships with optional Filament integration for table search and global search.

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
- [Working with Laravel Scout](#working-with-laravel-scout)

## Support This Project

If you find this package useful in your projects, please consider supporting its development. Your support helps maintain and improve this package, along with [other open-source PHP packages](https://mozex.dev/docs).

[![GitHub Sponsors](https://img.shields.io/badge/Sponsor-GitHub-ea4aaa?style=flat-square&logo=github)](https://github.com/sponsors/mozex)

## Installation

> **Requires [PHP 8.2+](https://php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/laravel-searchable/v1/requirements)

```bash
composer require mozex/laravel-searchable
```

That's it. No config files to publish, no migrations to run.

## Basic Usage

Add the `Searchable` trait to your model and define which columns should be searchable:

```php
use Mozex\Searchable\Searchable;

class Post extends Model
{
    use Searchable;

    public function searchableColumns(): array
    {
        return ['title', 'body', 'author.name', 'category.name'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

Then search in any query:

```php
// Search across all configured columns
Post::query()->search('laravel')->get();

// Chain with other query constraints
Post::query()
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

For polymorphic relations, use `relation:morphType.column` notation. The morph type should match your morph map alias:

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

If a BelongsTo relation points to a model on a different database connection, the package detects this automatically. It runs a separate query on the external connection, fetches matching IDs (capped at 50), and uses `whereIn` on the foreign key. No configuration needed.

The same works for morph relations to external connections.

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

All three parameters accept either a string or an array.

## Filament Integration

The package registers an `advancedSearchable()` macro on Filament's `TextColumn` when Filament is installed. Add it to one column in your table, and it searches across all your model's configured searchable columns:

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

For Filament's global search, register the provider in your panel:

```php
use Mozex\Searchable\Filament\SearchableGlobalSearchProvider;

return $panel
    ->id('admin')
    ->path('admin')
    ->globalSearch(SearchableGlobalSearchProvider::class);
```

This automatically uses your model's `searchableColumns()` for any resource whose model uses the `Searchable` trait. Resources without the trait fall back to Filament's default global search.

## Working with Laravel Scout

This package coexists with Laravel Scout without conflicts. Scout adds a static `search()` method on the model class (`Post::search('term')`), while this package adds a query scope (`Post::query()->search('term')`). They don't collide.

If you need to use a different scope name (for any reason), the Filament macro supports a `method` parameter:

```php
TextColumn::make('title')
    ->advancedSearchable(method: 'databaseSearch')
```

## Testing

```bash
composer test
```

## Resources

Visit the [documentation site](https://mozex.dev/docs/laravel-searchable/v1) for searchable docs auto-updated from this repository.

- **[Requirements](https://mozex.dev/docs/laravel-searchable/v1/requirements)**: PHP, Laravel, and dependency versions
- **[Changelog](https://mozex.dev/docs/laravel-searchable/v1/changelog)**: Release history with linked pull requests and diffs
- **[Contributing](https://mozex.dev/docs/laravel-searchable/v1/contributing)**: Development setup, code quality, and PR guidelines
- **[Questions & Issues](https://mozex.dev/docs/laravel-searchable/v1/questions-and-issues)**: Bug reports, feature requests, and help
- **[Security](mailto:hello@mozex.dev)**: Report vulnerabilities directly via email

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
