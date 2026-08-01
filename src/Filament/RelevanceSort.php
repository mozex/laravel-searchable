<?php

declare(strict_types=1);

namespace Mozex\Searchable\Filament;

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mozex\Searchable\Searchable;

class RelevanceSort
{
    public static bool $enabled = true;

    /**
     * Rank every Searchable-model table by relevance while a search is active,
     * with zero per-table setup.
     *
     * Registered once as a global Filament table configuration. It adds a query
     * scope (via modifyQueryUsing) that runs on the outer query before sorting,
     * so the relevance order leads. Crucially, it only kicks in while there's a
     * search term and the user hasn't picked a column to sort by, so a table's
     * own (possibly complex) defaultSort is left untouched when not searching
     * and simply becomes the tiebreaker when searching. An explicit column sort
     * always wins.
     */
    public static function register(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table->modifyQueryUsing(self::scope(...));
        });
    }

    /**
     * The query scope applied to every table. Filament injects $livewire.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function scope(Builder $query, HasTable $livewire, bool $isResolvingRecord = false): Builder
    {
        if (self::$enabled && ! $isResolvingRecord) {
            self::apply(
                $query,
                $livewire->getTableSearch(), // @phpstan-ignore method.notFound
                $livewire->getTableSortColumn(),
            );
        }

        return $query;
    }

    /**
     * Apply relevance ordering unless a sort is already selected, there is no
     * search term, or the model isn't searchable. Kept separate so the decision
     * is testable without a live Filament table.
     *
     * @param  Builder<Model>  $query
     * @param  string|array<int, string>  $in
     * @param  string|array<int, string>  $include
     * @param  string|array<int, string>  $except
     */
    public static function apply(
        Builder $query,
        ?string $search,
        ?string $sortColumn,
        string|array $in = [],
        string|array $include = [],
        string|array $except = [],
        int $externalLimit = 50,
        int $maxTerms = 10
    ): void {
        if (filled($sortColumn)) {
            return;
        }

        if (blank($search)) {
            return;
        }

        $model = $query->getModel();

        if (! in_array(Searchable::class, class_uses_recursive($model), true)) {
            return;
        }

        $model->applyRelevanceSort( // @phpstan-ignore method.notFound
            $query,
            $search,
            in: $in,
            include: $include,
            except: $except,
            externalLimit: $externalLimit,
            maxTerms: $maxTerms,
        );
    }
}
