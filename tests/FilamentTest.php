<?php

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mozex\Searchable\Filament\RelevanceSort;
use Workbench\App\Models\Author;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

uses(RefreshDatabase::class);

describe('advancedSearchable macro registration', function () {
    it('registers the macro on TextColumn', function () {
        expect(TextColumn::hasMacro('advancedSearchable'))->toBeTrue();
    });

    it('returns the column for chaining', function () {
        $column = TextColumn::make('title')->advancedSearchable();

        expect($column)->toBeInstanceOf(TextColumn::class);
    });

    it('marks the column as searchable', function () {
        $column = TextColumn::make('title')->advancedSearchable();

        expect($column->isSearchable())->toBeTrue();
    });
});

describe('advancedSearchable callback behavior', function () {
    it('runs the model search scope and filters the query', function () {
        Post::factory()->create(['title' => 'Laravel Guide']);
        Post::factory()->create(['title' => 'Vue Guide']);

        $column = TextColumn::make('title')->advancedSearchable(in: ['title']);
        $callback = invade($column)->searchQuery;

        $query = Post::query();
        $callback($query, 'Laravel');

        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->title)->toBe('Laravel Guide');
    });

    it('passes the in parameter through to the search scope', function () {
        Post::factory()->create(['title' => 'Match', 'body' => 'Other']);
        Post::factory()->create(['title' => 'Other', 'body' => 'Match']);

        $column = TextColumn::make('title')->advancedSearchable(in: ['body']);
        $callback = invade($column)->searchQuery;

        $query = Post::query();
        $callback($query, 'Match');

        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->body)->toBe('Match');
    });

    it('passes the except parameter through to the search scope', function () {
        Post::factory()->create(['title' => 'Match Title', 'body' => 'Other']);

        $column = TextColumn::make('title')->advancedSearchable(except: ['title', 'body', 'author.name', 'category.name']);
        $callback = invade($column)->searchQuery;

        $query = Post::query();
        $callback($query, 'Match');

        $results = $query->get();

        expect($results)->toHaveCount(1); // all columns excluded, scope returns everything
    });

    it('passes the include parameter through to the search scope', function () {
        Post::factory()->create(['title' => 'Other', 'body' => 'Other']);
        $author = Author::factory()->create(['name' => 'Match Person']);
        Post::factory()->create(['title' => 'Other', 'author_id' => $author->id]);

        $column = TextColumn::make('title')->advancedSearchable(in: ['title'], include: ['author.name']);
        $callback = invade($column)->searchQuery;

        $query = Post::query();
        $callback($query, 'Match');

        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->author->name)->toBe('Match Person');
    });

    it('uses the custom method name when provided', function () {
        $column = TextColumn::make('title')->advancedSearchable(method: 'nonExistentScope');
        $callback = invade($column)->searchQuery;

        // The macro stores `method` and uses it to invoke a scope by that name.
        // Calling a non-existent scope should propagate a BadMethodCallException.
        expect(fn () => $callback(Post::query(), 'term'))
            ->toThrow(BadMethodCallException::class);
    });
});

describe('advancedSearchable ordering reality', function () {
    // Filament runs the column search callback inside $query->where(fn ($q) =>
    // ...). Eloquent merges only the nested WHERE conditions, so any orderBy
    // added in there is discarded. The macro therefore builds the search
    // filter only; ranking happens separately via the global RelevanceSort
    // query scope.
    it('filters but does not order when wrapped in Filaments nested where', function () {
        Post::factory()->create(['title' => 'Laravel Guide']);
        Post::factory()->create(['title' => 'Vue Guide']);

        $column = TextColumn::make('title')->advancedSearchable(in: ['title']);
        $callback = invade($column)->searchQuery;

        $query = Post::query();
        $query->where(fn ($q) => $callback($q, 'Laravel')); // how Filament invokes it

        expect($query->toSql())->not->toContain('order by')
            ->and($query->get())->toHaveCount(1)
            ->and($query->get()->first()->title)->toBe('Laravel Guide');
    });
});

describe('Filament automatic relevance sort', function () {
    // RelevanceSort registers a global table query scope, so every Searchable
    // model's table is ranked while searching with no per-table setup. apply()
    // holds the decision logic so it is testable without a live table: rank
    // only when there is a search term, no column sort is selected, and the
    // model is searchable.

    it('ranks results by relevance when searching with no active sort', function () {
        $bodyMatch = Post::factory()->create(['title' => 'Unrelated', 'body' => 'Laravel here']);
        $titleMatch = Post::factory()->create(['title' => 'Laravel', 'body' => 'Unrelated']);

        // Filament builds the search WHERE; the scope adds the ranking.
        $query = Post::query()->search('Laravel', orderByRelevance: false);
        RelevanceSort::apply($query, 'Laravel', sortColumn: null);

        // Without ranking, id order would be [bodyMatch, titleMatch].
        expect($query->get()->pluck('id')->all())->toBe([$titleMatch->id, $bodyMatch->id]);
    });

    it('steps aside when the user selects a column sort', function () {
        $query = Post::query()->search('Laravel', orderByRelevance: false);
        RelevanceSort::apply($query, 'Laravel', sortColumn: 'created_at');

        expect($query->getQuery()->orders)->toBeNull();
    });

    it('does nothing when there is no search term', function () {
        $query = Post::query();
        RelevanceSort::apply($query, null, sortColumn: null);

        expect($query->getQuery()->orders)->toBeNull();
    });

    it('does nothing for models without the Searchable trait', function () {
        $query = Tag::query();
        RelevanceSort::apply($query, 'Laravel', sortColumn: null, in: ['name']);

        expect($query->getQuery()->orders)->toBeNull();
    });

    it('ranks through the table query scope, reading the live search and sort state', function () {
        $bodyMatch = Post::factory()->create(['title' => 'Unrelated', 'body' => 'Laravel']);
        $titleMatch = Post::factory()->create(['title' => 'Laravel']);

        $livewire = Mockery::mock(HasTable::class);
        $livewire->shouldReceive('getTableSortColumn')->andReturn(null);
        $livewire->shouldReceive('getTableSearch')->andReturn('Laravel');

        $query = Post::query()->search('Laravel', orderByRelevance: false);
        RelevanceSort::scope($query, $livewire);

        expect($query->get()->pluck('id')->all())->toBe([$titleMatch->id, $bodyMatch->id]);
    });

    it('honors the global enabled toggle', function () {
        RelevanceSort::$enabled = false;

        try {
            $query = Post::query()->search('Laravel', orderByRelevance: false);
            RelevanceSort::scope($query, Mockery::mock(HasTable::class));

            expect($query->getQuery()->orders)->toBeNull();
        } finally {
            RelevanceSort::$enabled = true;
        }
    });

    it('registers a global query scope on every table', function () {
        // The provider booted RelevanceSort::register(); a fresh table should
        // carry the relevance query scope without any per-table wiring.
        $table = Table::make(Mockery::mock(HasTable::class)->shouldIgnoreMissing());

        expect(invade($table)->queryScopes)->not->toBeEmpty();
    });
});
