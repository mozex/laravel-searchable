<?php

declare(strict_types=1);

namespace Mozex\Searchable;

use Filament\Tables\Columns\TextColumn;
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
        if (! class_exists(TextColumn::class)) {
            return;
        }

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
                    );
                }
            );

            return $this;
        });
    }
}
