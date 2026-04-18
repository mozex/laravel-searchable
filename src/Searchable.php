<?php

declare(strict_types=1);

namespace Mozex\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

trait Searchable
{
    /**
     * @return array<int, string>
     */
    public function searchableColumns(): array
    {
        return [];
    }

    /**
     * Invoke the search scope directly, bypassing the query builder.
     *
     * Use this when the model's Builder already has a search() method
     * (e.g., from a third-party package like Corcel) and the scope
     * can't be reached through $query->search().
     *
     * @param  Builder<static>  $query
     * @param  string|array<int, string>  $in
     * @param  string|array<int, string>  $include
     * @param  string|array<int, string>  $except
     */
    public function applySearch(
        Builder $query,
        ?string $search,
        string|array $in = [],
        string|array $include = [],
        string|array $except = []
    ): void {
        $this->scopeSearch($query, $search, $in, $include, $except);
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|array<int, string>  $in
     * @param  string|array<int, string>  $include
     * @param  string|array<int, string>  $except
     */
    protected function scopeSearch(
        Builder $query,
        ?string $search,
        string|array $in = [],
        string|array $include = [],
        string|array $except = []
    ): void {
        if (empty($search)) {
            return;
        }

        $columns = $this->resolveSearchColumns($in, $include, $except);

        if ($columns->isEmpty()) {
            return;
        }

        $query->where(function (Builder $query) use ($columns, $search): void {
            $grouped = $this->groupColumnsByType($query, $columns);

            foreach ($grouped['external_morph'] as $column) {
                $this->applyExternalMorphSearch($query, $column, $search);
            }

            foreach ($grouped['morph'] as $column) {
                $this->applyMorphSearch($query, $column, $search);
            }

            foreach ($grouped['external'] as $column) {
                $this->applyExternalRelationSearch($query, $column, $search);
            }

            foreach ($grouped['relation'] as $column) {
                $this->applyRelationSearch($query, $column, $search);
            }

            foreach ($grouped['direct'] as $column) {
                $this->applyDirectSearch($query, $column, $search);
            }
        });
    }

    /**
     * @param  string|array<int, string>  $in
     * @param  string|array<int, string>  $include
     * @param  string|array<int, string>  $except
     * @return Collection<int, string>
     */
    protected function resolveSearchColumns(
        string|array $in,
        string|array $include,
        string|array $except
    ): Collection {
        return collect(! empty($in) ? Arr::wrap($in) : $this->searchableColumns())
            ->when(
                ! empty($include),
                fn (Collection $columns): Collection => $columns->merge(Arr::wrap($include))
            )
            ->when(
                ! empty($except),
                fn (Collection $columns): Collection => $columns->diff(Arr::wrap($except))
            )
            ->filter()
            ->values();
    }

    /**
     * @param  Builder<static>  $query
     * @param  Collection<int, string>  $columns
     * @return array{
     *     direct: Collection<int, string>,
     *     relation: Collection<int, string>,
     *     morph: Collection<int, string>,
     *     external: Collection<int, string>,
     *     external_morph: Collection<int, string>,
     * }
     */
    protected function groupColumnsByType(Builder $query, Collection $columns): array
    {
        return [
            'direct' => $columns->reject(
                fn (string $column): bool => $this->isRelationColumn($column)
            ),
            'relation' => $columns->filter(
                fn (string $column): bool => $this->isRelationColumn($column)
                    && ! $this->isMorphColumn($column)
                    && ! $this->isExternalRelation($query, $column)
            ),
            'morph' => $columns->filter(
                fn (string $column): bool => $this->isMorphColumn($column)
                    && ! $this->isExternalMorph($column)
            ),
            'external' => $columns->filter(
                fn (string $column): bool => $this->isRelationColumn($column)
                    && ! $this->isMorphColumn($column)
                    && $this->isExternalRelation($query, $column)
            ),
            'external_morph' => $columns->filter(
                fn (string $column): bool => $this->isMorphColumn($column)
                    && $this->isExternalMorph($column)
            ),
        ];
    }

    protected function isRelationColumn(string $column): bool
    {
        return str_contains($column, '.');
    }

    protected function isMorphColumn(string $column): bool
    {
        return str_contains($column, ':') && str_contains($column, '.');
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function isExternalRelation(Builder $query, string $column): bool
    {
        [$relationName] = explode('.', $column, 2);

        $relation = $query->getRelation($relationName);

        if (! $relation instanceof BelongsTo) {
            return false;
        }

        return $this->resolveConnectionName($relation->getRelated()->getConnectionName())
            !== $this->resolveConnectionName($this->getConnectionName());
    }

    protected function isExternalMorph(string $column): bool
    {
        [, , $morphModel] = $this->parseMorphColumn($column);

        return $this->resolveConnectionName($morphModel->getConnectionName())
            !== $this->resolveConnectionName($this->getConnectionName());
    }

    protected function resolveConnectionName(?string $connection): string
    {
        /** @var string */
        return $connection ?? config('database.default');
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitRelationColumn(string $column): array
    {
        /** @var array{0: string, 1: string} */
        return explode('.', $column, 2);
    }

    /**
     * @return array{0: string, 1: string, 2: Model, 3: string}
     */
    protected function parseMorphColumn(string $column): array
    {
        [$relationName, $rest] = explode(':', $column, 2);
        [$morphType, $columnName] = explode('.', $rest, 2);

        /** @var class-string<Model> $morphClass */
        $morphClass = Model::getActualClassNameForMorph($morphType);

        return [$relationName, $columnName, new $morphClass, $morphType];
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyDirectSearch(Builder $query, string $column, string $search): void
    {
        $query->orWhereLike($column, "%{$search}%");
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyRelationSearch(Builder $query, string $column, string $search): void
    {
        [$relationName, $columnName] = $this->splitRelationColumn($column);

        $query->orWhereHas(
            $relationName,
            fn (Builder $q): Builder => $q->whereLike($columnName, "%{$search}%")
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyExternalRelationSearch(Builder $query, string $column, string $search): void
    {
        [$relationName, $columnName] = $this->splitRelationColumn($column);

        /** @var BelongsTo<Model, static> $relation */
        $relation = $query->getRelation($relationName);

        $query->orWhereIn(
            $relation->getForeignKeyName(),
            $relation->getRelated()
                ->newQuery()
                ->whereLike($columnName, "%{$search}%")
                ->take(50)
                ->pluck($relation->getRelated()->getKeyName())
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyMorphSearch(Builder $query, string $column, string $search): void
    {
        [$relationName, $columnName, , $morphType] = $this->parseMorphColumn($column);

        $query->orWhereHasMorph(
            $relationName,
            $morphType,
            function (Builder $q) use ($columnName, $search): void {
                if ($this->isRelationColumn($columnName)) {
                    [$subRelation, $subColumn] = $this->splitRelationColumn($columnName);

                    $q->whereHas(
                        $subRelation,
                        fn (Builder $q): Builder => $q->whereLike($subColumn, "%{$search}%")
                    );

                    return;
                }

                $q->whereLike($columnName, "%{$search}%");
            }
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyExternalMorphSearch(Builder $query, string $column, string $search): void
    {
        [$relationName, $columnName, $morphModel, $morphType] = $this->parseMorphColumn($column);

        $query->orWhere(
            fn (Builder $q): Builder => $q
                ->where("{$relationName}_type", $morphType)
                ->whereIn(
                    "{$relationName}_id",
                    $morphModel->newQuery()
                        ->whereLike($columnName, "%{$search}%")
                        ->take(50)
                        ->pluck($morphModel->getKeyName())
                )
        );
    }
}
