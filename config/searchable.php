<?php

declare(strict_types=1);

return [
    /*
     * The most terms a multi-word search is split into. Words past this cap
     * are dropped, so a pasted paragraph can't build a huge query. Set it to
     * 1 to match the whole search string as one literal phrase. A maxTerms
     * argument on a single call still wins over this value.
     */
    'max_terms' => (int) env('SEARCHABLE_MAX_TERMS', 10),

    /*
     * How many matching keys a cross-database column fetches per search term
     * before they're fed into a whereIn on the main query. An externalLimit
     * argument on a single call still wins over this value.
     */
    'external_limit' => (int) env('SEARCHABLE_EXTERNAL_LIMIT', 50),

    'filament' => [
        /*
         * Rank every Filament table on a Searchable model by relevance while
         * a search is active and no column sort is picked. Set to false to
         * keep each table's own sort order.
         */
        'relevance_sort' => (bool) env('SEARCHABLE_FILAMENT_RELEVANCE_SORT', true),
    ],
];
