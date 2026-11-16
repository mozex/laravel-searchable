<?php

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Mozex\Searchable\Filament\RelevanceSort;
use Workbench\App\Filament\Tables\Columns\CustomTextColumn;
use Workbench\App\Filament\Tables\CustomTable;
use Workbench\App\Models\Author;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

uses(RefreshDatabase::class);

describe('advancedSearchable macro registration', function () {
    // The macro is registered the first time a column is built, so booting
    // the app doesn't load TextColumn. flushMacros() stands in for a process
    // that hasn't built a column yet; Filament registers no component macros
    // of its own, so it removes only ours.
    afterEach(fn () => TextColumn::flushMacros());

    it('registers the macro the first time a column is built', function () {
        TextColumn::flushMacros();

        expect(TextColumn::hasMacro('advancedSearchable'))->toBeFalse();

        TextColumn::make('title');

        expect(TextColumn::hasMacro('advancedSearchable'))->toBeTrue();
    });

    it('is in place on a subclass of TextColumn', function () {
        TextColumn::flushMacros();

        expect(CustomTextColumn::make('title')->advancedSearchable()->isSearchable())->toBeTrue();
    });

    it('is in place for the app own configureUsing callbacks', function (string $class) {
        TextColumn::flushMacros();

        $column = $class::configureUsing(
            fn (Column $column) => $column instanceof TextColumn ? $column->advancedSearchable() : null,
            during: fn (): TextColumn => TextColumn::make('title'),
        );

        expect($column->isSearchable())->toBeTrue();
    })->with([
        'TextColumn' => TextColumn::class,
        'Column' => Column::class,
    ]);

    it('leaves an advancedSearchable macro of the app own alone', function () {
        TextColumn::flushMacros();
        TextColumn::macro('advancedSearchable', fn (): string => 'own');

        expect(TextColumn::make('title')->advancedSearchable())->toBe('own');
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
    it('splits terms up to the configured max_terms', function () {
        Post::factory()->create(['title' => 'Laravel Guide']);

        Config::set('searchable.max_terms', 1);

        $column = TextColumn::make('title')->advancedSearchable(in: ['title']);
        $callback = invade($column)->searchQuery;

        $query = Post::query();
        $callback($query, 'Guide Laravel');

        expect($query->get())->toBeEmpty();
    });

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

    it('registers the query scope on a subclass of Table', function () {
        $table = CustomTable::make(Mockery::mock(HasTable::class)->shouldIgnoreMissing());

        expect(invade($table)->queryScopes)->not->toBeEmpty();
    });
});

describe('boot', function () {
    // Each run boots a fresh app in its own process (tests/Fixtures/boot.php):
    // this process has long since loaded every table class.
    function bootInFreshProcess(string ...$arguments): array
    {
        // Notices PHP prints before Laravel's error handler takes over go to
        // stderr, so stdout carries only the JSON report.
        $result = Process::run([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/Fixtures/boot.php', ...$arguments]);

        expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }

    it('loads no table class while booting', function () {
        $report = bootInFreshProcess();

        expect($report['booted'])->toBeTrue()
            ->and($report['loaded'])->toBe([
                'Table' => false,
                'Column' => false,
                'TextColumn' => false,
                'ComponentManager' => true,
            ])
            ->and($report['configured'])->toContain(Table::class, Column::class)
            ->and($report['macroBeforeColumn'])->toBeFalse()
            ->and($report['searchableColumn'])->toBeTrue();
    });

    it('boots in an app without Filament', function () {
        $report = bootInFreshProcess('--without-filament');

        expect($report['booted'])->toBeTrue()
            ->and($report['loaded']['ComponentManager'])->toBeFalse();
    });
});
