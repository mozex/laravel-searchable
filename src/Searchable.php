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
        string|array $except = [],
        int $externalLimit = 50,
        bool $orderByRelevance = true
    ): void {
        $this->scopeSearch($query, $search, $in, $include, $except, $externalLimit, $orderByRelevance);
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
        string|array $except = [],
        int $externalLimit = 50,
        bool $orderByRelevance = true
    ): void {
        if (empty($search)) {
            return;
        }

        $columns = $this->resolveSearchColumns($in, $include, $except);

        if ($columns->isEmpty()) {
            return;
        }

        $query->where(function (Builder $query) use ($columns, $search, $externalLimit): void {
            $grouped = $this->groupColumnsByType($query, $columns);

            foreach ($grouped['external_morph'] as $column) {
                $this->applyExternalMorphSearch($query, $column, $search, $externalLimit);
            }

            foreach ($grouped['morph'] as $column) {
                $this->applyMorphSearch($query, $column, $search);
            }

            foreach ($grouped['external'] as $column) {
                $this->applyExternalRelationSearch($query, $column, $search, $externalLimit);
            }

            foreach ($grouped['relation'] as $column) {
                $this->applyRelationSearch($query, $column, $search);
            }

            foreach ($grouped['direct'] as $column) {
                $this->applyDirectSearch($query, $column, $search);
            }
        });

        if ($orderByRelevance) {
            $this->applyRelevanceOrder($query, $columns, $search, $externalLimit);
        }
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
     * Apply a case-insensitive LIKE constraint.
     *
     * Both operands are lowercased so matching stays case-insensitive even on
     * binary-collated columns - notably the JSON columns translatable models
     * use, which MySQL compares case-sensitively under a plain LIKE. Relying on
     * the column collation (as orWhereLike/whereLike do) silently misses matches
     * on those columns.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function whereSearchLike(Builder $query, string $column, string $search, bool $or = false): Builder
    {
        $sql = 'LOWER('.$query->getQuery()->getGrammar()->wrap($column).') LIKE ?';
        $bindings = ['%'.mb_strtolower($search).'%'];

        return $or
            ? $query->orWhereRaw($sql, $bindings)
            : $query->whereRaw($sql, $bindings);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyDirectSearch(Builder $query, string $column, string $search): void
    {
        $this->whereSearchLike($query, $column, $search, or: true);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyRelationSearch(Builder $query, string $column, string $search): void
    {
        [$relationName, $columnName] = $this->splitRelationColumn($column);

        $query->orWhereHas(
            $relationName,
            fn (Builder $q): Builder => $this->whereSearchLike($q, $columnName, $search)
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyExternalRelationSearch(Builder $query, string $column, string $search, int $limit): void
    {
        [$relationName, $columnName] = $this->splitRelationColumn($column);

        /** @var BelongsTo<Model, static> $relation */
        $relation = $query->getRelation($relationName);

        $query->orWhereIn(
            $relation->getForeignKeyName(),
            $this->whereSearchLike($relation->getRelated()->newQuery(), $columnName, $search)
                ->take($limit)
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
                        fn (Builder $q): Builder => $this->whereSearchLike($q, $subColumn, $search)
                    );

                    return;
                }

                $this->whereSearchLike($q, $columnName, $search);
            }
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function applyExternalMorphSearch(Builder $query, string $column, string $search, int $limit): void
    {
        [$relationName, $columnName, $morphModel, $morphType] = $this->parseMorphColumn($column);

        $query->orWhere(
            fn (Builder $q): Builder => $q
                ->where("{$relationName}_type", $morphType)
                ->whereIn(
                    "{$relationName}_id",
                    $this->whereSearchLike($morphModel->newQuery(), $columnName, $search)
                        ->take($limit)
                        ->pluck($morphModel->getKeyName())
                )
        );
    }

    /**
     * Apply only the relevance ordering, without any WHERE filtering.
     *
     * Useful when something else already filters the query and you just want
     * the ranking, e.g. a Filament table where Filament builds the search
     * WHERE and sorting is a separate phase. See the Filament RelevanceSort
     * helper.
     *
     * @param  Builder<static>  $query
     * @param  string|array<int, string>  $in
     * @param  string|array<int, string>  $include
     * @param  string|array<int, string>  $except
     */
    public function applyRelevanceSort(
        Builder $query,
        ?string $search,
        string|array $in = [],
        string|array $include = [],
        string|array $except = [],
        int $externalLimit = 50
    ): void {
        if (empty($search)) {
            return;
        }

        $columns = $this->resolveSearchColumns($in, $include, $except);

        if ($columns->isEmpty()) {
            return;
        }

        $this->applyRelevanceOrder($query, $columns, $search, $externalLimit);
    }

    /**
     * Order matches by relevance.
     *
     * Each searchable column contributes one ORDER BY key, emitted in the
     * column's declared order. The first column is the primary sort key, the
     * second breaks ties, and so on - so a match in an earlier column always
     * outranks a match that only occurs in a later one. Within a single column,
     * a graded score ranks exact matches above prefix matches above substring
     * matches.
     *
     * @param  Builder<static>  $query
     * @param  Collection<int, string>  $columns
     */
    protected function applyRelevanceOrder(Builder $query, Collection $columns, string $search, int $externalLimit): void
    {
        foreach ($columns as $column) {
            $term = $this->relevanceScoreFor($query, $column, $search, $externalLimit);

            if ($term === null) {
                continue;
            }

            [$sql, $bindings] = $term;

            $query->orderByRaw("({$sql}) desc", $bindings);
        }
    }

    /**
     * Build the relevance score expression for a single column.
     *
     * @param  Builder<static>  $query
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    protected function relevanceScoreFor(Builder $query, string $column, string $search, int $externalLimit): ?array
    {
        if (! $this->isRelationColumn($column)) {
            return $this->directRelevanceScore($query, $column, $search);
        }

        if ($this->isMorphColumn($column)) {
            return $this->isExternalMorph($column)
                ? $this->externalMorphRelevanceScore($query, $column, $search, $externalLimit)
                : $this->morphRelevanceScore($query, $column, $search);
        }

        if ($this->isExternalRelation($query, $column)) {
            return $this->externalRelationRelevanceScore($query, $column, $search, $externalLimit);
        }

        return $this->relationRelevanceScore($query, $column, $search);
    }

    /**
     * A graded, case-insensitive match score: 3 = exact, 2 = prefix, 1 = substring, 0 = none.
     *
     * @param  Builder<*>  $query
     * @return array{0: string, 1: array<int, string>}
     */
    protected function relevanceCase(Builder $query, string $column, string $search): array
    {
        $wrapped = 'LOWER('.$query->getQuery()->getGrammar()->wrap($column).')';
        $lower = mb_strtolower($search);

        $sql = "CASE WHEN {$wrapped} = ? THEN 3 WHEN {$wrapped} LIKE ? THEN 2 WHEN {$wrapped} LIKE ? THEN 1 ELSE 0 END";

        return [$sql, [$lower, $lower.'%', '%'.$lower.'%']];
    }

    /**
     * @param  Builder<static>  $query
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function directRelevanceScore(Builder $query, string $column, string $search): array
    {
        return $this->relevanceCase($query, $column, $search);
    }

    /**
     * @param  Builder<static>  $query
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function relationRelevanceScore(Builder $query, string $column, string $search): array
    {
        [$relationName, $columnName] = $this->splitRelationColumn($column);

        $relation = $query->getRelation($relationName);
        $related = $relation->getRelated();

        [$caseSql, $caseBindings] = $this->relevanceCase($related->newQuery(), $columnName, $search);

        $sub = $relation->getRelationExistenceQuery($related->newQuery(), $query, [])
            ->selectRaw("COALESCE(MAX({$caseSql}), 0)", $caseBindings);

        return [$sub->toSql(), $sub->getBindings()];
    }

    /**
     * Cross-database relations can't be correlated in SQL, so reuse the capped
     * subquery of matching keys and score a row by whether its foreign key is
     * among them.
     *
     * @param  Builder<static>  $query
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    protected function externalRelationRelevanceScore(Builder $query, string $column, string $search, int $limit): ?array
    {
        [$relationName, $columnName] = $this->splitRelationColumn($column);

        /** @var BelongsTo<Model, static> $relation */
        $relation = $query->getRelation($relationName);

        $ids = $this->whereSearchLike($relation->getRelated()->newQuery(), $columnName, $search)
            ->take($limit)
            ->pluck($relation->getRelated()->getKeyName())
            ->all();

        if ($ids === []) {
            return null;
        }

        $foreignKey = $query->getQuery()->getGrammar()->wrap($relation->getForeignKeyName());
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return ["CASE WHEN {$foreignKey} IN ({$placeholders}) THEN 1 ELSE 0 END", $ids];
    }

    /**
     * @param  Builder<static>  $query
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function morphRelevanceScore(Builder $query, string $column, string $search): array
    {
        [$relationName, $columnName, $morphModel, $morphType] = $this->parseMorphColumn($column);

        $typeColumn = $query->getQuery()->getGrammar()->wrap("{$relationName}_type");
        $outerKey = $query->getModel()->qualifyColumn("{$relationName}_id");

        if ($this->isRelationColumn($columnName)) {
            [$subRelation, $subColumn] = $this->splitRelationColumn($columnName);

            $sub = $morphModel->newQuery()
                ->selectRaw('1')
                ->whereColumn($morphModel->getQualifiedKeyName(), $outerKey)
                ->whereHas(
                    $subRelation,
                    fn (Builder $q): Builder => $this->whereSearchLike($q, $subColumn, $search)
                );

            return [
                "CASE WHEN {$typeColumn} = ? AND EXISTS ({$sub->toSql()}) THEN 1 ELSE 0 END",
                [$morphType, ...$sub->getBindings()],
            ];
        }

        [$caseSql, $caseBindings] = $this->relevanceCase($morphModel->newQuery(), $columnName, $search);

        $sub = $morphModel->newQuery()
            ->selectRaw("COALESCE(MAX({$caseSql}), 0)", $caseBindings)
            ->whereColumn($morphModel->getQualifiedKeyName(), $outerKey);

        return [
            "CASE WHEN {$typeColumn} = ? THEN ({$sub->toSql()}) ELSE 0 END",
            [$morphType, ...$sub->getBindings()],
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    protected function externalMorphRelevanceScore(Builder $query, string $column, string $search, int $limit): ?array
    {
        [$relationName, $columnName, $morphModel, $morphType] = $this->parseMorphColumn($column);

        $ids = $this->whereSearchLike($morphModel->newQuery(), $columnName, $search)
            ->take($limit)
            ->pluck($morphModel->getKeyName())
            ->all();

        if ($ids === []) {
            return null;
        }

        $grammar = $query->getQuery()->getGrammar();
        $typeColumn = $grammar->wrap("{$relationName}_type");
        $idColumn = $grammar->wrap("{$relationName}_id");
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return [
            "CASE WHEN {$typeColumn} = ? AND {$idColumn} IN ({$placeholders}) THEN 1 ELSE 0 END",
            [$morphType, ...$ids],
        ];
    }
}
