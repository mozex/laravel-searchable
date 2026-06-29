<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Workbench\App\Livewire\PostsTable;
use Workbench\App\Livewire\TagsTable;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

uses(RefreshDatabase::class);

function tableRecordIds(object $component): array
{
    $records = $component->instance()->getTableRecords();
    $collection = method_exists($records, 'getCollection') ? $records->getCollection() : $records;

    return $collection->pluck('id')->all();
}

it('ranks records by relevance in a real table while searching', function () {
    // Created in the "wrong" order so id-order and relevance-order disagree.
    $bodyMatch = Post::factory()->create(['title' => 'Unrelated', 'body' => 'Laravel here']);
    $titleMatch = Post::factory()->create(['title' => 'Laravel', 'body' => 'Unrelated']);

    $component = Livewire::test(PostsTable::class)->searchTable('Laravel');

    expect(tableRecordIds($component))->toBe([$titleMatch->id, $bodyMatch->id]);
});

it('lets relevance lead while the table own defaultSort breaks ties', function () {
    $x = Post::factory()->create(['title' => 'Laravel', 'body' => 'aaa']); // title match
    $y = Post::factory()->create(['title' => 'Laravel', 'body' => 'bbb']); // title match
    $z = Post::factory()->create(['title' => 'ccc', 'body' => 'Laravel']); // body match only

    $component = Livewire::test(PostsTable::class, ['useDefaultSort' => true])
        ->searchTable('Laravel');

    // x,y (title) outrank z (body); the table's own `defaultSort('id','desc')`
    // breaks the x/y tie -> y before x. So relevance leads, defaultSort follows.
    expect(tableRecordIds($component))->toBe([$y->id, $x->id, $z->id]);
});

it('drops relevance when the user sorts by a column', function () {
    $bodyMatch = Post::factory()->create(['title' => 'Aaa', 'body' => 'Laravel']);
    $titleMatch = Post::factory()->create(['title' => 'Laravel', 'body' => 'xxx']);

    $component = Livewire::test(PostsTable::class)
        ->searchTable('Laravel')
        ->sortTable('title', 'asc');

    // Relevance would put titleMatch first; an explicit title-asc sort wins and
    // 'Aaa' < 'Laravel', so bodyMatch comes first instead.
    expect(tableRecordIds($component))->toBe([$bodyMatch->id, $titleMatch->id]);
});

it('leaves the table own defaultSort untouched when not searching', function () {
    $a = Post::factory()->create(['title' => 'Alpha']);
    $b = Post::factory()->create(['title' => 'Beta']);
    $c = Post::factory()->create(['title' => 'Gamma']);

    $component = Livewire::test(PostsTable::class, ['useDefaultSort' => true]);

    // No search term: relevance never applies; defaultSort('id','desc') stands.
    expect(tableRecordIds($component))->toBe([$c->id, $b->id, $a->id]);
});

it('does not touch a table whose model is not searchable', function () {
    // The global hook runs on every table; for a non-Searchable model it must
    // be a harmless no-op and let native Filament search work normally.
    $laravel = Tag::create(['name' => 'Laravel']);
    Tag::create(['name' => 'Vue']);

    $component = Livewire::test(TagsTable::class)->searchTable('Laravel');

    expect(tableRecordIds($component))->toBe([$laravel->id]);
});
