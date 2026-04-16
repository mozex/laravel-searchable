<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mozex\Searchable\Filament\SearchableGlobalSearchProvider;
use Workbench\App\Filament\Resources\AuthorResource;
use Workbench\App\Filament\Resources\PostResource;
use Workbench\App\Models\Author;
use Workbench\App\Models\InheritedAuthor;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

uses(RefreshDatabase::class);

describe('hasSearchableTrait', function () {
    it('detects models that use the Searchable trait directly', function () {
        $provider = new SearchableGlobalSearchProvider;

        expect(invade($provider)->hasSearchableTrait(AuthorResource::class))->toBeTrue();
    });

    it('detects models inheriting the Searchable trait through a parent class', function () {
        $resource = new class extends Filament\Resources\Resource
        {
            protected static ?string $model = InheritedAuthor::class;
        };

        $provider = new SearchableGlobalSearchProvider;

        expect(invade($provider)->hasSearchableTrait($resource::class))->toBeTrue();
    });

    it('returns false for models that do not use the Searchable trait', function () {
        $resource = new class extends Filament\Resources\Resource
        {
            protected static ?string $model = Tag::class;
        };

        $provider = new SearchableGlobalSearchProvider;

        expect(invade($provider)->hasSearchableTrait($resource::class))->toBeFalse();
    });
});

describe('getSearchableResults', function () {
    it('searches the model using the resource attributes when defined', function () {
        // PostResource overrides getGloballySearchableAttributes() to return ['title']
        Post::factory()->create(['title' => 'Match Title', 'body' => 'Match Body']);
        Post::factory()->create(['title' => 'Other Title', 'body' => 'Match Body']);

        $provider = new SearchableGlobalSearchProvider;
        $results = invade($provider)->getSearchableResults(PostResource::class, 'Match');

        // Only the row matching on `title` should be returned, not the one matching `body`
        expect($results)->toHaveCount(1);
    });

    it('searches all columns when the resource returns the full searchableColumns set', function () {
        // AuthorResource returns new Author()->searchableColumns() (name, email, posts.title).
        Author::factory()->create(['name' => 'Match Person', 'email' => 'unique@example.com']);
        Author::factory()->create(['name' => 'Other', 'email' => 'match-target@example.com']);
        Author::factory()->create(['name' => 'Other', 'email' => 'unrelated@example.com']);

        $provider = new SearchableGlobalSearchProvider;
        $results = invade($provider)->getSearchableResults(AuthorResource::class, 'match');

        // Both `name` and `email` are searched, so two records match
        expect($results)->toHaveCount(2);
    });
});

describe('getResults', function () {
    it('returns results categorized by resource label', function () {
        Author::factory()->create(['name' => 'Searchable Author']);

        $provider = new SearchableGlobalSearchProvider;
        $results = $provider->getResults('Searchable');

        expect($results)->not->toBeNull()
            ->and($results->getCategories())->toHaveKey(AuthorResource::getPluralModelLabel());
    });

    it('returns empty categories when no results match', function () {
        Author::factory()->create(['name' => 'Author One']);

        $provider = new SearchableGlobalSearchProvider;
        $results = $provider->getResults('NoMatchAtAll');

        expect($results->getCategories())->toBeEmpty();
    });
});
