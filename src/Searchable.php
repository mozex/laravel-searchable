<?php

declare(strict_types=1);

namespace Mozex\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

trait Searchable
{
    /**
     * The character that escapes LIKE wildcards in a search term.
     *
     * Chosen over the conventional backslash because it needs no escaping of
     * its own inside a SQL string literal, so the same `ESCAPE '!'` clause is
     * valid on MySQL, PostgreSQL, SQLite and SQL Server alike.
     */
    protected const LIKE_ESCAPE = '!';

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
        bool $orderByRelevance = true,
        int $maxTerms = 10
    ): void {
        $this->scopeSearch($query, $search, $in, $include, $except, $externalLimit, $orderByRelevance, $maxTerms);
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
        bool $orderByRelevance = true,
        int $maxTerms = 10
    ): void {
        $terms = $this->parseSearchTerms($search, $maxTerms);

        if ($terms === []) {
            return;
        }

        $columns = $this->resolveSearchColumns($in, $include, $except);

        if ($columns->isEmpty()) {
            return;
        }

        $grouped = $this->groupColumnsByType($query, $columns);

        // Fetched once and shared by the WHERE and the ORDER BY, so a search
        // costs one round trip per external column per term instead of two.
        $externalKeys = $this->resolveExternalKeys($query, $grouped, $terms, $externalLimit);

        $query->where(function (Builder $query) use ($grouped, $terms, $externalKeys): void {
            // A single term keeps the original flat OR group, so the common
            // one-word search compiles to exactly the SQL it always has.
            if (count($terms) === 1) {
                $this->applyTermGroup($query, $grouped, $terms[0], $externalKeys);

                return;
            }

            // Every term has to match somewhere, but each is free to match a
            // different column: (a OR b) AND (a OR b).
            foreach ($terms as $term) {
                $query->where(function (Builder $query) use ($grouped, $term, $externalKeys): void {
                    $this->applyTermGroup($query, $grouped, $term, $externalKeys);
                });
            }
        });

        if ($orderByRelevance) {
            $this->applyRelevanceOrder($query, $columns, $terms, $externalKeys);
        }
    }

    /**
     * Split a search string into the terms that all have to match.
     *
     * Whitespace separates terms, and a double-quoted run is kept together as a
     * single term so phrases can still be searched verbatim. A search with no
     * whitespace yields exactly one term, which keeps the generated SQL
     * identical to a plain phrase search.
     *
     * @return array<int, string>
     */
    protected function parseSearchTerms(?string $search, int $maxTerms): array
    {
        $phrase = trim((string) $search);

        if ($phrase === '') {
            return [];
        }

        // Nothing to split: no whitespace to break on, no quotes to strip.
        // Also the escape hatch back to phrase-only matching (maxTerms: 1).
        if ($maxTerms <= 1 || preg_match('/[\s"]/u', $phrase) !== 1) {
            return [$phrase];
        }

        preg_match_all('/"([^"]*)"|(\S+)/u', $phrase, $matches, PREG_SET_ORDER);

        $terms = [];

        foreach ($matches as $match) {
            // Group 1 is a quoted phrase, group 2 a bare word. An unbalanced
            // quote falls through to group 2, so drop the stray quote instead
            // of searching for it literally.
            $term = $match[1] !== ''
                ? trim($match[1])
                : trim(trim($match[2] ?? ''), '"');

            if ($term !== '') {
                $terms[] = $term;
            }
        }

        // Every token was quote noise, e.g. a lone `"` or `""`. Fall back to the
        // literal string: returning nothing here would drop the WHERE and hand
        // back the whole table for a search the user did type something into.
        if ($terms === []) {
            return [$phrase];
        }

        // A term cap keeps a pasted paragraph from expanding into hundreds of
        // predicates and subqueries.
        return array_slice($terms, 0, $maxTerms);
    }

    /**
     * The whole search string, used to rank a contiguous phrase match above any
     * combination of individual term matches.
     *
     * @param  array<int, string>  $terms
     */
    protected function searchPhrase(array $terms): string
    {
        return implode(' ', $terms);
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
                    && ! $this->isMorphToColumn($query, $column)
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

    /**
     * Add every column's condition for one term as an OR group.
     *
     * @param  Builder<static>  $query
     * @param  array{
     *     direct: Collection<int, string>,
     *     relation: Collection<int, string>,
     *     morph: Collection<int, string>,
     *     external: Collection<int, string>,
     *     external_morph: Collection<int, string>,
     * }  $grouped
     * @param  array<string, array<int, mixed>>  $externalKeys
     */
    protected function applyTermGroup(Builder $query, array $grouped, string $term, array $externalKeys): void
    {
        foreach ($grouped['external_morph'] as $column) {
            $this->applyExternalMorphSearch($query, $column, $term, $externalKeys);
        }

        foreach ($grouped['morph'] as $column) {
            $this->applyMorphSearch($query, $column, $term);
        }

        foreach ($grouped['external'] as $column) {
            $this->applyExternalRelationSearch($query, $column, $term, $externalKeys);
        }

        foreach ($grouped['relation'] as $column) {
            $this->applyRelationSearch($query, $column, $term);
        }

        foreach ($grouped['direct'] as $column) {
            $this->applyDirectSearch($query, $column, $term);
        }
    }

    protected function isRelationColumn(string $column): bool
    {
        return str_contains($column, '.');
    }

    /**
     * More than one hop away, e.g. `posts.author.name`. Such a column is
     * searched with a nested whereHas, but it can't be graded in one statement.
     */
    protected function isNestedRelationColumn(string $column): bool
    {
        return substr_count($column, '.') > 1;
    }

    /**
     * A MorphTo referenced via dot notation (e.g. `commentable.title`) can't be
     * resolved to a single related model, so it can't be searched or scored.
     * Use the typed morph syntax (`commentable:type.title`) instead. Such a
     * column is skipped rather than producing invalid SQL.
     *
     * @param  Builder<static>  $query
     */
    protected function isMorphToColumn(Builder $query, string $column): bool
    {
        [$relationName] = explode('.', $column, 2);

        return $query->getRelation($relationName) instanceof MorphTo;
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
        // Only a direct BelongsTo can be swapped for a whereIn on the foreign
        // key. A nested path has no single local key to match against, so it
        // falls through to the regular relation search.
        if ($this->isNestedRelationColumn($column)) {
            return false;
        }

        [$relationName] = explode('.', $column, 2);

        $relation = $query->getRelation($relationName);

        if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
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
     * Split a relation column into its relation path and the target column.
     *
     * The last segment is always the column; everything before it is the
     * relation path, which may be nested (`posts.author.name` becomes
     * `posts.author` plus `name`). Eloquent's whereHas reads the dotted path
     * natively, so nesting needs no extra handling.
     *
     * @return array{0: string, 1: string}
     */
    protected function splitRelationColumn(string $column): array
    {
        $position = (int) strrpos($column, '.');

        return [substr($column, 0, $position), substr($column, $position + 1)];
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
     * Neutralise the LIKE wildcards in a user-supplied term.
     *
     * Without this a search for `_` matches every row and a search for `100%`
     * matches anything starting with `100`, which is both wrong and an easy way
     * for a public search box to force a match-everything scan.
     */
    protected function escapeLikeWildcards(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $value
        );
    }

    /**
     * Build the `LIKE` fragment for a column, wildcards escaped.
     *
     * @param  Builder<*>  $query
     * @return array{0: string, 1: array<int, string>}
     */
    protected function likeFragment(Builder $query, string $column, string $search): array
    {
        $sql = 'LOWER('.$query->getQuery()->getGrammar()->wrap($column).") LIKE ? ESCAPE '".self::LIKE_ESCAPE."'";

        return [$sql, ['%'.$this->escapeLikeWildcards(mb_strtolower($search)).'%']];
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
        [$sql, $bindings] = $this->likeFragment($query, $column, $search);

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
        [$relationPath, $columnName] = $this->splitRelationColumn($column);

        $query->orWhereHas(
            $relationPath,
            fn (Builder $q): Builder => $this->whereSearchLike($q, $columnName, $search)
        );
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<string, array<int, mixed>>  $externalKeys
     */
    protected function applyExternalRelationSearch(Builder $query, string $column, string $search, array $externalKeys): void
    {
        [$relationName] = $this->splitRelationColumn($column);

        /** @var BelongsTo<Model, static> $relation */
        $relation = $query->getRelation($relationName);

        $query->orWhereIn(
            $relation->getForeignKeyName(),
            $externalKeys[$this->externalKeyIndex($column, $search)] ?? []
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
                    [$relationPath, $subColumn] = $this->splitRelationColumn($columnName);

                    $q->whereHas(
                        $relationPath,
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
     * @param  array<string, array<int, mixed>>  $externalKeys
     */
    protected function applyExternalMorphSearch(Builder $query, string $column, string $search, array $externalKeys): void
    {
        [$relationName, , , $morphType] = $this->parseMorphColumn($column);

        $query->orWhere(
            fn (Builder $q): Builder => $q
                ->where("{$relationName}_type", $morphType)
                ->whereIn(
                    "{$relationName}_id",
                    $externalKeys[$this->externalKeyIndex($column, $search)] ?? []
                )
        );
    }

    /**
     * Fetch, once per column and term, the keys matching on the other
     * connection. Cross-database JOINs aren't possible, so the search runs
     * there and comes back as a capped list of keys for a whereIn.
     *
     * @param  Builder<static>  $query
     * @param  array{
     *     direct: Collection<int, string>,
     *     relation: Collection<int, string>,
     *     morph: Collection<int, string>,
     *     external: Collection<int, string>,
     *     external_morph: Collection<int, string>,
     * }  $grouped
     * @param  array<int, string>  $terms
     * @return array<string, array<int, mixed>>
     */
    protected function resolveExternalKeys(Builder $query, array $grouped, array $terms, int $limit): array
    {
        $keys = [];

        foreach ($grouped['external'] as $column) {
            [$relationName, $columnName] = $this->splitRelationColumn($column);

            $related = $query->getRelation($relationName)->getRelated();

            foreach ($terms as $term) {
                $keys[$this->externalKeyIndex($column, $term)] = $this->fetchExternalKeys($related, $columnName, $term, $limit);
            }
        }

        foreach ($grouped['external_morph'] as $column) {
            [, $columnName, $morphModel] = $this->parseMorphColumn($column);

            foreach ($terms as $term) {
                $keys[$this->externalKeyIndex($column, $term)] = $this->fetchExternalKeys($morphModel, $columnName, $term, $limit);
            }
        }

        return $keys;
    }

    protected function externalKeyIndex(string $column, string $term): string
    {
        return $column."\0".$term;
    }

    /**
     * @return array<int, mixed>
     */
    protected function fetchExternalKeys(Model $related, string $column, string $term, int $limit): array
    {
        return $this->whereSearchLike($related->newQuery(), $column, $term)
            ->take($limit)
            ->pluck($related->getKeyName())
            ->all();
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
        int $externalLimit = 50,
        int $maxTerms = 10
    ): void {
        $terms = $this->parseSearchTerms($search, $maxTerms);

        if ($terms === []) {
            return;
        }

        $columns = $this->resolveSearchColumns($in, $include, $except);

        if ($columns->isEmpty()) {
            return;
        }

        $externalKeys = $this->resolveExternalKeys(
            $query,
            $this->groupColumnsByType($query, $columns),
            $terms,
            $externalLimit
        );

        $this->applyRelevanceOrder($query, $columns, $terms, $externalKeys);
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
     * @param  array<int, string>  $terms
     * @param  array<string, array<int, mixed>>  $externalKeys
     */
    protected function applyRelevanceOrder(Builder $query, Collection $columns, array $terms, array $externalKeys): void
    {
        foreach ($columns as $column) {
            $term = $this->relevanceScoreFor($query, $column, $terms, $externalKeys);

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
     * @param  array<int, string>  $terms
     * @param  array<string, array<int, mixed>>  $externalKeys
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    protected function relevanceScoreFor(Builder $query, string $column, array $terms, array $externalKeys): ?array
    {
        if (! $this->isRelationColumn($column)) {
            return $this->directRelevanceScore($query, $column, $terms);
        }

        if ($this->isMorphColumn($column)) {
            return $this->isExternalMorph($column)
                ? $this->externalMorphRelevanceScore($query, $column, $terms, $externalKeys)
                : $this->morphRelevanceScore($query, $column, $terms);
        }

        if ($this->isMorphToColumn($query, $column)) {
            return null;
        }

        if ($this->isExternalRelation($query, $column)) {
            return $this->externalRelationRelevanceScore($query, $column, $terms, $externalKeys);
        }

        if ($this->isNestedRelationColumn($column)) {
            return $this->nestedRelationRelevanceScore($query, $column, $terms);
        }

        return $this->relationRelevanceScore($query, $column, $terms);
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
        $pattern = $this->escapeLikeWildcards($lower);
        $escape = " ESCAPE '".self::LIKE_ESCAPE."'";

        $sql = "CASE WHEN {$wrapped} = ? THEN 3"
            ." WHEN {$wrapped} LIKE ?{$escape} THEN 2"
            ." WHEN {$wrapped} LIKE ?{$escape} THEN 1 ELSE 0 END";

        return [$sql, [$lower, $pattern.'%', '%'.$pattern.'%']];
    }

    /**
     * The graded score for a column across every term.
     *
     * With one term this is the plain graded CASE. With several, each term
     * contributes its own graded score and the whole phrase contributes one
     * more, scaled so that any phrase match outranks every combination of
     * individual term matches - a title reading "Laravel Guide" beats one
     * reading "Guide to Laravel" for the search "laravel guide".
     *
     * @param  Builder<*>  $query
     * @param  array<int, string>  $terms
     * @return array{0: string, 1: array<int, string>}
     */
    protected function gradedRelevanceScore(Builder $query, string $column, array $terms): array
    {
        if (count($terms) === 1) {
            return $this->relevanceCase($query, $column, $terms[0]);
        }

        // The per-term scores top out at 3 each, so one more than their sum is
        // the smallest weight that can't be outscored by them.
        $phraseWeight = 3 * count($terms) + 1;

        [$sql, $bindings] = $this->relevanceCase($query, $column, $this->searchPhrase($terms));

        $parts = ["({$sql}) * {$phraseWeight}"];

        foreach ($terms as $term) {
            [$termSql, $termBindings] = $this->relevanceCase($query, $column, $term);

            $parts[] = "({$termSql})";
            $bindings = [...$bindings, ...$termBindings];
        }

        return [implode(' + ', $parts), $bindings];
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<int, string>  $terms
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function directRelevanceScore(Builder $query, string $column, array $terms): array
    {
        return $this->gradedRelevanceScore($query, $column, $terms);
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<int, string>  $terms
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function relationRelevanceScore(Builder $query, string $column, array $terms): array
    {
        [$relationName, $columnName] = $this->splitRelationColumn($column);

        $relation = $query->getRelation($relationName);
        $related = $relation->getRelated();

        [$caseSql, $caseBindings] = $this->gradedRelevanceScore($related->newQuery(), $columnName, $terms);

        $sub = $relation->getRelationExistenceQuery($related->newQuery(), $query, [])
            ->selectRaw("COALESCE(MAX({$caseSql}), 0)", $caseBindings);

        return [$sub->toSql(), $sub->getBindings()];
    }

    /**
     * A column more than one hop away can't be graded in a single statement, so
     * it scores 1 when the far column matches every term and 0 otherwise. That
     * keeps it to one correlated subquery no matter how many terms there are.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $terms
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function nestedRelationRelevanceScore(Builder $query, string $column, array $terms): array
    {
        [$relationPath, $columnName] = $this->splitRelationColumn($column);
        [$relationName, $rest] = explode('.', $relationPath, 2);

        $relation = $query->getRelation($relationName);
        $related = $relation->getRelated();

        $sub = $relation->getRelationExistenceQuery($related->newQuery(), $query, [])
            ->selectRaw('1')
            ->whereHas($rest, function (Builder $q) use ($columnName, $terms): void {
                foreach ($terms as $term) {
                    $this->whereSearchLike($q, $columnName, $term);
                }
            });

        return ["CASE WHEN EXISTS ({$sub->toSql()}) THEN 1 ELSE 0 END", $sub->getBindings()];
    }

    /**
     * Cross-database relations can't be correlated in SQL, so reuse the keys
     * already fetched for the WHERE and score a row by whether its foreign key
     * matches every term.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $terms
     * @param  array<string, array<int, mixed>>  $externalKeys
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    protected function externalRelationRelevanceScore(Builder $query, string $column, array $terms, array $externalKeys): ?array
    {
        [$relationName] = $this->splitRelationColumn($column);

        /** @var BelongsTo<Model, static> $relation */
        $relation = $query->getRelation($relationName);

        $foreignKey = $query->getQuery()->getGrammar()->wrap($relation->getForeignKeyName());

        $conditions = $this->externalKeyConditions($column, $foreignKey, $terms, $externalKeys);

        if ($conditions === null) {
            return null;
        }

        [$sql, $bindings] = $conditions;

        return ["CASE WHEN {$sql} THEN 1 ELSE 0 END", $bindings];
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<int, string>  $terms
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function morphRelevanceScore(Builder $query, string $column, array $terms): array
    {
        [$relationName, $columnName, $morphModel, $morphType] = $this->parseMorphColumn($column);

        $typeColumn = $query->getQuery()->getGrammar()->wrap("{$relationName}_type");
        $outerKey = $query->getModel()->qualifyColumn("{$relationName}_id");

        if ($this->isRelationColumn($columnName)) {
            [$relationPath, $subColumn] = $this->splitRelationColumn($columnName);

            $sub = $morphModel->newQuery()
                ->selectRaw('1')
                ->whereColumn($morphModel->getQualifiedKeyName(), $outerKey)
                ->whereHas($relationPath, function (Builder $q) use ($subColumn, $terms): void {
                    foreach ($terms as $term) {
                        $this->whereSearchLike($q, $subColumn, $term);
                    }
                });

            return [
                "CASE WHEN {$typeColumn} = ? AND EXISTS ({$sub->toSql()}) THEN 1 ELSE 0 END",
                [$morphType, ...$sub->getBindings()],
            ];
        }

        [$caseSql, $caseBindings] = $this->gradedRelevanceScore($morphModel->newQuery(), $columnName, $terms);

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
     * @param  array<int, string>  $terms
     * @param  array<string, array<int, mixed>>  $externalKeys
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    protected function externalMorphRelevanceScore(Builder $query, string $column, array $terms, array $externalKeys): ?array
    {
        [$relationName, , , $morphType] = $this->parseMorphColumn($column);

        $grammar = $query->getQuery()->getGrammar();
        $typeColumn = $grammar->wrap("{$relationName}_type");
        $idColumn = $grammar->wrap("{$relationName}_id");

        $conditions = $this->externalKeyConditions($column, $idColumn, $terms, $externalKeys);

        if ($conditions === null) {
            return null;
        }

        [$sql, $bindings] = $conditions;

        return [
            "CASE WHEN {$typeColumn} = ? AND {$sql} THEN 1 ELSE 0 END",
            [$morphType, ...$bindings],
        ];
    }

    /**
     * An `IN (...)` test per term against the keys fetched from the other
     * connection. Null when some term matched nothing there, since no row can
     * then match them all and the column has nothing to contribute.
     *
     * @param  array<int, string>  $terms
     * @param  array<string, array<int, mixed>>  $externalKeys
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    protected function externalKeyConditions(string $column, string $keyColumn, array $terms, array $externalKeys): ?array
    {
        $conditions = [];
        $bindings = [];

        foreach ($terms as $term) {
            $keys = $externalKeys[$this->externalKeyIndex($column, $term)] ?? [];

            if ($keys === []) {
                return null;
            }

            $placeholders = implode(', ', array_fill(0, count($keys), '?'));

            $conditions[] = "{$keyColumn} IN ({$placeholders})";
            $bindings = [...$bindings, ...$keys];
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
