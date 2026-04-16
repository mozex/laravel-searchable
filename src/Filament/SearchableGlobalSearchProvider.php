<?php

declare(strict_types=1);

namespace Mozex\Searchable\Filament;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mozex\Searchable\Searchable;

class SearchableGlobalSearchProvider implements GlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $builder = GlobalSearchResults::make();

        $resources = Filament::getResources();

        usort(
            $resources,
            fn (string $a, string $b): int => ($a::getGlobalSearchSort() ?? 0) <=> ($b::getGlobalSearchSort() ?? 0),
        );

        foreach ($resources as $resource) {
            if (! $resource::canGloballySearch()) {
                continue;
            }

            $resourceResults = $this->hasSearchableTrait($resource)
                ? $this->getSearchableResults($resource, $query)
                : $resource::getGlobalSearchResults($query);

            if (! $resourceResults->count()) {
                continue;
            }

            $builder->category($resource::getPluralModelLabel(), $resourceResults);
        }

        return $builder;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function hasSearchableTrait(string $resource): bool
    {
        return in_array(
            Searchable::class,
            class_uses_recursive($resource::getModel())
        );
    }

    /**
     * @param  class-string<resource>  $resource
     * @return Collection<int, GlobalSearchResult>
     */
    protected function getSearchableResults(string $resource, string $search): Collection
    {
        /** @var Builder<Model> $query */
        $query = $resource::getGlobalSearchEloquentQuery();

        // The model is verified to use the Searchable trait via hasSearchableTrait()
        $query->search($search); // @phpstan-ignore method.notFound

        $resource::modifyGlobalSearchQuery($query, $search);

        return $this->buildResults($resource, $query);
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  Builder<Model>  $query
     * @return Collection<int, GlobalSearchResult>
     */
    protected function buildResults(string $resource, Builder $query): Collection
    {
        return $query
            ->limit($resource::getGlobalSearchResultsLimit())
            ->get()
            ->map(function (Model $record) use ($resource): ?GlobalSearchResult {
                $url = $resource::getGlobalSearchResultUrl($record);

                if (blank($url)) {
                    return null;
                }

                return new GlobalSearchResult(
                    title: $resource::getGlobalSearchResultTitle($record),
                    url: $url,
                    details: $resource::getGlobalSearchResultDetails($record),
                    actions: array_map(
                        fn (Action $action): Action => $action->hasRecord() ? $action : $action->record($record),
                        $resource::getGlobalSearchResultActions($record),
                    ),
                );
            })
            ->filter();
    }
}
