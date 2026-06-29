<?php

declare(strict_types=1);

namespace Mozex\Searchable;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Searchable\Filament\RelevanceSort;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SearchableServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-searchable');
    }

    public function packageBooted(): void
    {
        $this->registerFilamentMacros();
        $this->registerFilamentRelevanceSort();
    }

    protected function registerFilamentRelevanceSort(): void
    {
        if (! class_exists(Table::class)) {
            return;
        }

        RelevanceSort::register();
    }

    protected function registerFilamentMacros(): void
    {
        if (! class_exists(TextColumn::class)) {
            return;
        }

        // Builds the search WHERE only. Filament runs this inside a nested
        // WHERE closure, so relevance ordering can't ride along here (the
        // orderBy would be discarded). Ranking is applied separately and
        // automatically by the global RelevanceSort query scope (registered in
        // registerFilamentRelevanceSort).
        TextColumn::macro('advancedSearchable', function (
            array|string $in = [],
            array|string $include = [],
            array|string $except = [],
            int $externalLimit = 50,
            string $method = 'search'
        ) {
            $this->searchable( // @phpstan-ignore method.notFound
                query: function (Builder $query, string $search) use ($in, $include, $except, $externalLimit, $method): void {
                    $query->{$method}(
                        search: $search,
                        in: $in,
                        include: $include,
                        except: $except,
                        externalLimit: $externalLimit,
                        orderByRelevance: false,
                    );
                }
            );

            return $this;
        });
    }
}
