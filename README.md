# Laravel Searchable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-searchable.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)
[![GitHub Checks Action Status](https://img.shields.io/github/actions/workflow/status/mozex/laravel-searchable/checks.yml?branch=main&label=checks&style=flat-square)](https://github.com/mozex/laravel-searchable/actions?query=workflow%3AChecks+branch%3Amain)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-searchable/v1)
[![License](https://img.shields.io/packagist/l/mozex/laravel-searchable?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-searchable.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-searchable)

Add a `Searchable` trait to any Eloquent model and search across multiple columns, regular relations, polymorphic relations, and even cross-database relations with a single `->search()` call. Works alongside Laravel Scout. Ships with optional Filament integration for table search and global search.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/laravel-searchable/v1)**: searchable docs, version requirements, detailed changelog, and more.

## Table of Contents

- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Search Types](#search-types)
  - [Direct Columns](#direct-columns)
  - [Relation Columns](#relation-columns)
  - [Morph Relations](#morph-relations)
  - [Cross-Database Relations](#cross-database-relations)
- [Case Sensitivity](#case-sensitivity)
- [Column Filtering](#column-filtering)
- [Relevance Ordering](#relevance-ordering)
- [Performance](#performance)
- [Filament Integration](#filament-integration)
  - [Ranked Table Search](#ranked-table-search)
  - [Global Search](#global-search)
- [Handling Conflicts](#handling-conflicts)
  - [Laravel Scout](#laravel-scout)
  - [Existing `search` Methods](#existing-search-methods)

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

Dot is for regular relations (`author.name`), colon is for morph relations where you have to name the target type because the package can't infer it (`commentable:post.title`).

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

Prefer the typed colon syntax for morphs. A `MorphTo` written in plain dot notation (`commentable.title`, no type) still searches through `whereHas`, but it can't be relevance-ranked (the package can't resolve a single target model to score), so it's left out of the ordering. Name the type with the colon syntax to get ranking too.

### Cross-Database Relations

If a BelongsTo relation points to a model on a different database connection, the package picks this up on its own. Since cross-database JOINs aren't possible, it runs a separate query on the external connection, fetches matching IDs (capped at 50 by default), and uses `whereIn` on the foreign key.

Morph relations to external connections work the same way.

The cap keeps the resulting `IN (...)` clause from getting unmanageably large when a search term hits a lot of rows on the external side. If 50 isn't the right number for your data, pass `externalLimit`:

```php
Post::search('term', externalLimit: 200)->get();
```

The same parameter works on `applySearch()` and on the Filament `advancedSearchable()` macro.

## Case Sensitivity

Searches are case-insensitive by default. `Comment::search('LARAVEL')` matches rows containing `laravel`, `Laravel`, or `LARAVEL` without any extra flag or argument.

This comes from Laravel's `whereLike` helper, which the package uses for every search type (direct, relation, morph, and external). Actual behavior follows your database:

- **MySQL / MariaDB**: case-insensitive on the usual `_ci` collations like `utf8mb4_unicode_ci` and `utf8mb4_0900_ai_ci`. A column on a `_bin` or `_cs` collation matches case-sensitively.
- **PostgreSQL**: always case-insensitive. `whereLike` compiles to `ILIKE`.
- **SQLite**: case-insensitive for ASCII only. `Café` and `café` won't match each other under the default `LIKE`.

The package doesn't expose a flag to flip this. If you need case-sensitive matching on a normally case-insensitive column, change the column's collation at the database level.

## Column Filtering

You can override or adjust which columns are searched per-query:

```php
// Search only specific columns (ignores searchableColumns)
Post::search('term', in: ['title', 'body'])->get();

// Add extra columns on top of searchableColumns
Post::search('term', include: ['slug'])->get();

// Exclude specific columns from searchableColumns
Post::search('term', except: ['author.name'])->get();

// Combine them
Post::search('term', include: ['slug'], except: ['body'])->get();
```

All three parameters accept a string or an array.

## Relevance Ordering

Results come back ranked by how well they match, not by `id`. The order of your `searchableColumns()` array sets the priority: a hit in the first column outranks a hit that only shows up in a later one.

Say a `Course` searches `['title', 'description']`. Search "laravel", and a course with "Laravel" in its title sorts above one that only mentions it in the description, even when the description course was created first. Title is column 0, so it wins. This is the whole point. Before, both matched equally and you had to scroll to find the one you meant.

Within a single column, closer matches rank higher. An exact value beats a prefix, which beats a buried substring. Searching "laravel" against three titles:

- `Laravel` ranks first (exact match)
- `Laravel Guide` ranks next (starts with the term)
- `Best Laravel Tips` ranks last (term sits in the middle)

The same grading runs on relation and morph columns. `author.name` is scored right after `title`, `commentable:post.title` in its own array position, and so on down the list. A HasMany relation is scored by its best-matching child, so an author with a post titled exactly "Laravel" sorts above one with "A Laravel Tutorial".

Cross-database relations and two-hop morph columns like `commentable:post.author.name` get a simpler matched-or-not score instead of the exact/prefix/substring grading, because those can't be ranked inside a single SQL statement. Column priority still holds for them.

Ordering is on by default. Turn it off with `orderByRelevance: false`:

```php
Post::search('laravel', orderByRelevance: false)->get();
```

The same parameter works on `applySearch()`.

Add your own `orderBy()` before `search()` and yours stays the primary sort, with relevance as the tiebreaker:

```php
// created_at drives the order, relevance only breaks ties
Post::query()->orderByDesc('created_at')->search('laravel')->get();
```

Call `orderBy()` after `search()` and the roles flip: relevance leads, your column breaks ties.

In Filament tables this is automatic while searching. See [Ranked Table Search](#ranked-table-search).

## Performance

This package compiles to `LIKE '%term%'`. The leading wildcard defeats B-tree indexes, so every row in the searched column gets scanned, and each relation or morph column adds a correlated `EXISTS` subquery on top. On small-to-mid tables this is fine; into the millions of rows, or once a search hits many relations, switch to Laravel Scout with Meilisearch, Typesense, or Algolia. Indexing the searched columns themselves won't help, but indexing the columns you also filter on (e.g., `tenant_id`, `status`) lets the database prune rows before the `LIKE` runs. On Postgres, a `pg_trgm` GIN index is the one thing that genuinely speeds up `LIKE '%term%'` while staying in SQL.

Relevance ordering adds a scoring expression to the `ORDER BY` for each searchable column, and for relation and morph columns that's another correlated subquery. It only runs over rows that already passed the `WHERE`, so the cost tracks the number of matches, not the table size. If you don't need ranked results, `orderByRelevance: false` skips all of it.

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

### Ranked Table Search

Tables rank by relevance automatically while searching. There's nothing to add to your tables. When the package boots with Filament present, it registers a global table query scope, so any table whose model uses the `Searchable` trait floats the best matches to the top the moment someone types in the search box, using the same column-priority and exact/prefix/substring rules from [Relevance Ordering](#relevance-ordering).

It's deliberately careful about not stepping on your existing sorts:

- **Not searching?** It does nothing. Your table's own `defaultSort`, however complex, runs exactly as before.
- **Searching?** Relevance leads, and your `defaultSort` becomes the tiebreaker behind it. Your sort still runs; it just settles ties among equally relevant rows.
- **Clicked a column header to sort?** Relevance gets out of the way completely and the chosen sort wins.

This works because Filament's search and sort are separate phases. The macro can't rank on its own (Filament runs the search callback inside a nested `WHERE`, and Eloquent throws away any `orderBy` added there), so the ranking rides on a query scope that runs before sorting instead.

If you'd rather wire ranking yourself, turn the automatic behavior off once, anywhere in a service provider:

```php
use Mozex\Searchable\Filament\RelevanceSort;

RelevanceSort::$enabled = false;
```

Then apply it where you want, for example inside a `modifyQueryUsing` or a custom sort, reusing the same decision logic:

```php
// $search and $sortColumn come from your table's livewire component
RelevanceSort::apply($query, $search, $sortColumn);
```

### Global Search

Global search ranks results on its own. The provider applies relevance ordering for you, so the most relevant hits land at the top of each resource's results with no extra wiring.

Register the provider on your panel:

```php
use Mozex\Searchable\Filament\SearchableGlobalSearchProvider;

return $panel
    ->id('admin')
    ->path('admin')
    ->globalSearch(SearchableGlobalSearchProvider::class);
```

Then on each resource, define `getGloballySearchableAttributes()` to control which columns global search uses for that resource. Return all of the model's columns, or a subset:

```php
class CourseResource extends Resource
{
    // Use everything the model declared as searchable
    public static function getGloballySearchableAttributes(): array
    {
        return (new Course)->searchableColumns();
    }
}

class PostResource extends Resource
{
    // Or limit global search to a subset, even though
    // the Post model has more columns in searchableColumns()
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'author.name'];
    }
}
```

Each resource you want in global search needs to define `getGloballySearchableAttributes()`. Resources without it are excluded from global search entirely.

Resources whose models don't use the `Searchable` trait fall through to Filament's default global search behavior.

## Handling Conflicts

### Laravel Scout

Scout and this package both expose a `search()` method on your model. Scout's is a static method that hits its search engine; this package's is a query scope that runs SQL. Technically, different call paths, so they don't collide.

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

Now `Lesson::search('term')` runs Scout's full-text search, and `Lesson::databaseSearch('term')` runs this package's database search. No ambiguity.

For the Filament macro, pass the renamed method:

```php
TextColumn::make('name')->advancedSearchable(method: 'databaseSearch')
```

### Existing `search` Methods

Sometimes you can't reach this package's scope through `$query->search()` because something else already owns that name. Two common cases:

- **A custom Eloquent Builder defines its own `search()`** (Corcel's `PostBuilder` is the textbook example). `$query->search()` calls the Builder's method, not this package's scope.
- **A parent model you extend already declares `scopeSearch`** with a different signature. Adding our trait causes a fatal error because PHP enforces method signature compatibility between trait methods and inherited methods.

For both cases, use `applySearch()` to invoke the scope directly without going through the `search` name:

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

If a parent model's `scopeSearch` signature conflicts with this package's, alias our scope to a different name when adding the trait (the same pattern as the Scout case above):

```php
use Mozex\Searchable\Searchable as DatabaseSearchable;

class Product extends VendorModel
{
    use DatabaseSearchable {
        scopeSearch as scopeDatabaseSearch;
    }
}
```

For the Builder case specifically, you can also override the Builder's `search()` to delegate back to `applySearch`, so the rest of your codebase keeps calling `$query->search()`:

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
