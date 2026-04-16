<?php

use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\Author;
use Workbench\App\Models\Post;

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
