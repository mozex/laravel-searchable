<?php

declare(strict_types=1);

namespace Mozex\Searchable;

use Illuminate\Database\Eloquent\Builder;
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
    }

    protected function registerFilamentMacros(): void
    {
        $textColumnClass = 'Filament\\Tables\\Columns\\TextColumn';

        if (! class_exists($textColumnClass)) {
            return;
        }

        $textColumnClass::macro('advancedSearchable', function (
            array|string $in = [],
            array|string $include = [],
            array|string $except = [],
            string $method = 'search'
        ) {
            /** @phpstan-ignore method.notFound */
            $this->searchable(
                query: function (Builder $query, string $search) use ($in, $include, $except, $method): void {
                    $query->{$method}(
                        search: $search,
                        in: $in,
                        include: $include,
                        except: $except,
                    );
                }
            );

            return $this;
        });
    }
}
