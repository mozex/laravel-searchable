<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Author;
use Workbench\App\Models\Category;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;

uses(RefreshDatabase::class);

describe('case-insensitive matching', function () {
    // Force LIKE to compare case-sensitively, mirroring MySQL's binary-collated
    // JSON columns (used by translatable models), where a plain LIKE silently
    // misses matches of a different case.
    beforeEach(function () {
        DB::connection('testing')->statement('PRAGMA case_sensitive_like = ON');
    });

    afterEach(function () {
        DB::connection('testing')->statement('PRAGMA case_sensitive_like = OFF');
    });

    it('matches a direct column regardless of case', function () {
        // The reported bug: a "Clean Water" row was found for "Clean" or "water"
        // but not for the lowercased phrase "clean water".
        Post::factory()->create(['title' => 'Clean Water']);

        expect(Post::query()->search('clean water', in: ['title'])->get())->toHaveCount(1)
            ->and(Post::query()->search('CLEAN WATER', in: ['title'])->get())->toHaveCount(1)
            ->and(Post::query()->search('Clean Water', in: ['title'])->get())->toHaveCount(1);
    });

    it('matches a relation column regardless of case', function () {
        $author = Author::factory()->create(['name' => 'Clean Water']);
        Post::factory()->create(['author_id' => $author->id, 'title' => 'Some post']);

        expect(Post::query()->search('clean water', in: ['author.name'])->get())->toHaveCount(1);
    });
});

describe('direct column search', function () {
    it('searches in a single column', function () {
        Post::factory()->create(['title' => 'Laravel Testing']);
        Post::factory()->create(['title' => 'Vue Components']);

        $results = Post::query()->search('Laravel', in: ['title'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->title)->toBe('Laravel Testing');
    });

    it('searches across multiple columns', function () {
        Post::factory()->create(['title' => 'Laravel', 'body' => 'PHP framework']);
        Post::factory()->create(['title' => 'React', 'body' => 'Laravel integration']);
        Post::factory()->create(['title' => 'Vue', 'body' => 'Frontend framework']);

        $results = Post::query()->search('Laravel', in: ['title', 'body'])->get();

        expect($results)->toHaveCount(2);
    });

    it('performs partial matching', function () {
        Post::factory()->create(['title' => 'Introduction to Laravel']);

        $results = Post::query()->search('Intro', in: ['title'])->get();

        expect($results)->toHaveCount(1);
    });

    it('returns no results when nothing matches', function () {
        Post::factory()->create(['title' => 'Laravel Testing']);

        $results = Post::query()->search('Python', in: ['title'])->get();

        expect($results)->toHaveCount(0);
    });
});

describe('empty search handling', function () {
    it('skips filtering for null search', function () {
        Post::factory()->count(3)->create();

        $results = Post::query()->search(null)->get();

        expect($results)->toHaveCount(3);
    });

    it('skips filtering for empty string search', function () {
        Post::factory()->count(3)->create();

        $results = Post::query()->search('')->get();

        expect($results)->toHaveCount(3);
    });

    it('returns all results when searchable columns resolve to empty', function () {
        Post::factory()->count(3)->create();

        $results = Post::query()
            ->search('test', except: ['title', 'body', 'author.name', 'category.name'])
            ->get();

        expect($results)->toHaveCount(3);
    });
});

describe('column resolution', function () {
    it('uses searchableColumns by default', function () {
        Author::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        Author::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

        $results = Author::query()->search('John')->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->name)->toBe('John Doe');
    });

    it('overrides searchableColumns with in parameter', function () {
        Post::factory()->create(['title' => 'Relevant Title', 'body' => 'Unrelated content']);
        Post::factory()->create(['title' => 'Unrelated Title', 'body' => 'Relevant content']);

        $results = Post::query()->search('Relevant', in: ['body'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->body)->toBe('Relevant content');
    });

    it('accepts string for in parameter', function () {
        Post::factory()->create(['title' => 'Match Title']);
        Post::factory()->create(['title' => 'Other']);

        $results = Post::query()->search('Match', in: 'title')->get();

        expect($results)->toHaveCount(1);
    });

    it('merges include parameter with searchable columns', function () {
        Post::factory()->create(['title' => 'No match', 'body' => 'Match text']);

        $results = Post::query()->search('Match', in: ['title'], include: ['body'])->get();

        expect($results)->toHaveCount(1);
    });

    it('excludes columns via except parameter', function () {
        Post::factory()->create(['title' => 'Unique Title', 'body' => 'Generic body']);

        $results = Post::query()->search('Unique', in: ['title', 'body'], except: ['title'])->get();

        expect($results)->toHaveCount(0);
    });

    it('accepts string for include and except parameters', function () {
        Post::factory()->create(['title' => 'Match', 'body' => 'Other']);

        $results = Post::query()->search('Match', in: 'body', include: 'title')->get();

        expect($results)->toHaveCount(1);
    });
});

describe('relation search', function () {
    it('searches in BelongsTo relation columns', function () {
        $john = Author::factory()->create(['name' => 'John Doe']);
        $jane = Author::factory()->create(['name' => 'Jane Smith']);

        Post::factory()->create(['author_id' => $john->id, 'title' => 'Post A']);
        Post::factory()->create(['author_id' => $jane->id, 'title' => 'Post B']);

        $results = Post::query()->search('John', in: ['author.name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->title)->toBe('Post A');
    });

    it('searches in HasMany relation columns', function () {
        $author1 = Author::factory()->create(['name' => 'Alice']);
        $author2 = Author::factory()->create(['name' => 'Bob']);

        Post::factory()->create(['author_id' => $author1->id, 'title' => 'Laravel Guide']);
        Post::factory()->create(['author_id' => $author2->id, 'title' => 'Vue Guide']);

        $results = Author::query()->search('Laravel', in: ['posts.title'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->name)->toBe('Alice');
    });

    it('combines direct and relation search results', function () {
        $john = Author::factory()->create(['name' => 'John']);
        $jane = Author::factory()->create(['name' => 'Jane']);

        Post::factory()->create(['author_id' => $john->id, 'title' => 'Jane post']);
        Post::factory()->create(['author_id' => $jane->id, 'title' => 'Other post']);
        Post::factory()->create(['author_id' => $john->id, 'title' => 'Other content']);

        $results = Post::query()->search('Jane', in: ['title', 'author.name'])->get();

        expect($results)->toHaveCount(2);
    });
});

describe('morph search', function () {
    it('searches in morph relation columns', function () {
        $post1 = Post::factory()->create(['title' => 'Laravel Testing']);
        $post2 = Post::factory()->create(['title' => 'Vue Components']);

        Comment::factory()->create([
            'commentable_type' => 'post',
            'commentable_id' => $post1->id,
            'body' => 'Comment A',
        ]);
        Comment::factory()->create([
            'commentable_type' => 'post',
            'commentable_id' => $post2->id,
            'body' => 'Comment B',
        ]);

        $results = Comment::query()->search('Laravel', in: ['commentable:post.title'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->body)->toBe('Comment A');
    });

    it('searches in morph relation with nested relation', function () {
        $john = Author::factory()->create(['name' => 'John Doe']);
        $jane = Author::factory()->create(['name' => 'Jane Smith']);

        $post1 = Post::factory()->create(['author_id' => $john->id]);
        $post2 = Post::factory()->create(['author_id' => $jane->id]);

        Comment::factory()->create([
            'commentable_type' => 'post',
            'commentable_id' => $post1->id,
            'body' => 'First',
        ]);
        Comment::factory()->create([
            'commentable_type' => 'post',
            'commentable_id' => $post2->id,
            'body' => 'Second',
        ]);

        $results = Comment::query()
            ->search('John', in: ['commentable:post.author.name'])
            ->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->body)->toBe('First');
    });
});

describe('untyped morph relation in dot notation', function () {
    // A MorphTo referenced with dot notation (e.g. `commentable.title`) can't
    // resolve to a single related model. Before the fix, relevance ordering
    // built a subquery against the wrong table and threw a SQL error. It must
    // be skipped instead, while sibling columns keep working.
    it('skips the column instead of throwing a SQL error', function () {
        $post = Post::factory()->create(['title' => 'Laravel']);
        $match = Comment::factory()->create([
            'body' => 'Laravel comment',
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
        ]);
        Comment::factory()->create([
            'body' => 'Unrelated',
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
        ]);

        $results = Comment::query()->search('Laravel', in: ['body', 'commentable.title'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($match->id);
    });

    it('does not add a relevance order key for the untyped morph column', function () {
        $query = Comment::query();
        $query->getModel()->applySearch($query, 'Laravel', in: ['body', 'commentable.title']);

        // Only `body` contributes an ORDER BY key; the MorphTo column is skipped.
        expect($query->getQuery()->orders)->toHaveCount(1);
    });
});

describe('external relation search', function () {
    it('searches in BelongsTo relation on external connection', function () {
        $cat1 = Category::factory()->create(['name' => 'Programming']);
        $cat2 = Category::factory()->create(['name' => 'Design']);

        Post::factory()->create(['category_id' => $cat1->id, 'title' => 'Post A']);
        Post::factory()->create(['category_id' => $cat2->id, 'title' => 'Post B']);

        $results = Post::query()->search('Programming', in: ['category.name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->title)->toBe('Post A');
    });

    it('caps the external subquery at 50 matching IDs by default', function () {
        // Cross-database search runs a subquery on the external connection capped at 50 IDs.
        // Create 60 matching categories with one post each. The search should hit at most 50 posts.
        for ($i = 1; $i <= 60; $i++) {
            $category = Category::factory()->create(['name' => "Tech Topic {$i}"]);
            Post::factory()->create(['category_id' => $category->id]);
        }

        $results = Post::query()->search('Tech Topic', in: ['category.name'])->get();

        expect($results->count())->toBeLessThanOrEqual(50);
    });

    it('allows the external cap to be configured via externalLimit', function () {
        for ($i = 1; $i <= 10; $i++) {
            $category = Category::factory()->create(['name' => "Tech Topic {$i}"]);
            Post::factory()->create(['category_id' => $category->id]);
        }

        $results = Post::query()
            ->search('Tech Topic', in: ['category.name'], externalLimit: 3)
            ->get();

        expect($results)->toHaveCount(3);
    });
});

describe('external morph search', function () {
    it('searches in morph relation on external connection', function () {
        $cat1 = Category::factory()->create(['name' => 'Web Development']);
        $cat2 = Category::factory()->create(['name' => 'Data Science']);

        Comment::factory()->create([
            'commentable_type' => 'category',
            'commentable_id' => $cat1->id,
            'body' => 'Comment X',
        ]);
        Comment::factory()->create([
            'commentable_type' => 'category',
            'commentable_id' => $cat2->id,
            'body' => 'Comment Y',
        ]);

        $results = Comment::query()
            ->search('Web Development', in: ['commentable:category.name'])
            ->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->body)->toBe('Comment X');
    });
});

describe('query integration', function () {
    it('applies search as AND with other query constraints', function () {
        Post::factory()->create(['title' => 'Laravel Guide', 'body' => 'Published content']);
        Post::factory()->create(['title' => 'Laravel Tips', 'body' => 'Draft content']);

        $results = Post::query()
            ->where('body', 'Published content')
            ->search('Laravel', in: ['title'])
            ->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->title)->toBe('Laravel Guide');
    });

    it('searches across all configured types simultaneously', function () {
        $author = Author::factory()->create(['name' => 'John']);
        $category = Category::factory()->create(['name' => 'John Reviews']);
        $post = Post::factory()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'title' => 'Some Post',
            'body' => 'Content',
        ]);

        $comment1 = Comment::factory()->create([
            'body' => 'John mentioned here',
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
        ]);

        $comment2 = Comment::factory()->create([
            'body' => 'Other comment',
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
        ]);

        $comment3 = Comment::factory()->create([
            'body' => 'Category comment',
            'commentable_type' => 'category',
            'commentable_id' => $category->id,
        ]);

        $otherAuthor = Author::factory()->create(['name' => 'Alice']);
        $otherPost = Post::factory()->create(['author_id' => $otherAuthor->id]);
        Comment::factory()->create([
            'body' => 'Unrelated',
            'commentable_type' => 'post',
            'commentable_id' => $otherPost->id,
        ]);

        $results = Comment::query()
            ->search('John', in: [
                'body',
                'commentable:post.author.name',
                'commentable:category.name',
            ])
            ->get();

        expect($results)->toHaveCount(3)
            ->and($results->pluck('id')->sort()->values()->all())
            ->toBe([$comment1->id, $comment2->id, $comment3->id]);
    });

    it('supports applySearch for models with Builder search conflicts', function () {
        Post::factory()->create(['title' => 'Laravel Guide']);
        Post::factory()->create(['title' => 'Vue Guide']);

        $query = Post::query();
        $query->getModel()->applySearch($query, 'Laravel', in: ['title']);
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->title)->toBe('Laravel Guide');
    });
});

describe('relevance ordering', function () {
    it('ranks earlier columns above later ones', function () {
        // Lower-priority match is created first (lower id) to prove ordering
        // is by relevance, not insertion order.
        $bodyMatch = Post::factory()->create(['title' => 'Unrelated', 'body' => 'Laravel here']);
        $titleMatch = Post::factory()->create(['title' => 'Laravel', 'body' => 'Unrelated']);

        $results = Post::query()->search('Laravel', in: ['title', 'body'])->get();

        expect($results->pluck('id')->all())->toBe([$titleMatch->id, $bodyMatch->id]);
    });

    it('ranks exact above prefix above substring matches within a column', function () {
        $substring = Post::factory()->create(['title' => 'Best Laravel Ever']);
        $prefix = Post::factory()->create(['title' => 'Laravel Guide']);
        $exact = Post::factory()->create(['title' => 'Laravel']);

        $results = Post::query()->search('Laravel', in: ['title'])->get();

        expect($results->pluck('id')->all())->toBe([$exact->id, $prefix->id, $substring->id]);
    });

    it('ranks a direct column match above a relation column match', function () {
        $author = Author::factory()->create(['name' => 'Laravel']);
        $relationMatch = Post::factory()->create(['author_id' => $author->id, 'title' => 'Unrelated']);
        $titleMatch = Post::factory()->create(['title' => 'Laravel']);

        $results = Post::query()->search('Laravel', in: ['title', 'author.name'])->get();

        expect($results->pluck('id')->all())->toBe([$titleMatch->id, $relationMatch->id]);
    });

    it('ranks a HasMany relation match by best match quality', function () {
        $weak = Author::factory()->create(['name' => 'Alice']);
        Post::factory()->create(['author_id' => $weak->id, 'title' => 'A Laravel Tutorial']);

        $strong = Author::factory()->create(['name' => 'Bob']);
        Post::factory()->create(['author_id' => $strong->id, 'title' => 'Laravel']);

        $results = Author::query()->search('Laravel', in: ['posts.title'])->get();

        expect($results->pluck('id')->all())->toBe([$strong->id, $weak->id]);
    });

    it('ranks a direct column match above a morph relation match', function () {
        $post = Post::factory()->create(['title' => 'Laravel']);
        $morphMatch = Comment::factory()->create([
            'body' => 'Unrelated',
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
        ]);
        $bodyMatch = Comment::factory()->create([
            'body' => 'Laravel',
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
        ]);

        $results = Comment::query()
            ->search('Laravel', in: ['body', 'commentable:post.title'])
            ->get();

        expect($results->pluck('id')->all())->toBe([$bodyMatch->id, $morphMatch->id]);
    });

    it('ranks an external relation match by column priority', function () {
        $category = Category::factory()->create(['name' => 'Laravel']);
        $externalMatch = Post::factory()->create(['category_id' => $category->id, 'title' => 'Unrelated']);
        $titleMatch = Post::factory()->create(['title' => 'Laravel']);

        $results = Post::query()->search('Laravel', in: ['title', 'category.name'])->get();

        expect($results->pluck('id')->all())->toBe([$titleMatch->id, $externalMatch->id]);
    });

    it('applies ordering by default and can be disabled', function () {
        $ordered = Post::query();
        $ordered->getModel()->applySearch($ordered, 'Laravel', in: ['title']);

        $unordered = Post::query();
        $unordered->getModel()->applySearch($unordered, 'Laravel', in: ['title'], orderByRelevance: false);

        expect($ordered->getQuery()->orders)->not->toBeNull()
            ->and($unordered->getQuery()->orders)->toBeNull();
    });

    it('keeps a user-defined order as the primary sort when applied first', function () {
        $first = Post::factory()->create(['title' => 'Laravel A']);
        $second = Post::factory()->create(['title' => 'Laravel B']);

        // User order applied before search => it stays the primary key,
        // relevance only breaks ties.
        $results = Post::query()
            ->orderByDesc('id')
            ->search('Laravel', in: ['title'])
            ->get();

        expect($results->pluck('id')->all())->toBe([$second->id, $first->id]);
    });
});

describe('LIKE wildcard escaping', function () {
    it('treats an underscore in the term as a literal character', function () {
        Author::factory()->create(['name' => 'Alice']);
        Author::factory()->create(['name' => 'Bob']);
        $literal = Author::factory()->create(['name' => 'snake_case']);

        // A bare `_` is a single-character LIKE wildcard. Unescaped it matched
        // every row, which is both wrong and a free full scan for anyone with
        // access to a public search box.
        $results = Author::query()->search('_', in: ['name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($literal->id);
    });

    it('treats a percent sign in the term as a literal character', function () {
        $literal = Author::factory()->create(['name' => '100% Cotton']);
        Author::factory()->create(['name' => '1000 Threads']);

        $results = Author::query()->search('100%', in: ['name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($literal->id);
    });

    it('treats the escape character itself as a literal character', function () {
        $literal = Author::factory()->create(['name' => 'Wow! Amazing']);
        Author::factory()->create(['name' => 'Nothing here']);

        $results = Author::query()->search('Wow!', in: ['name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($literal->id);
    });

    it('keeps the escape character out of the exact-match comparison', function () {
        // The `= ?` arm binds the raw term while the LIKE arms bind the escaped
        // one. Leaking the escaping into the equality would compare against
        // `wow!!`, so an exact row would score as a mere substring.
        $substring = Author::factory()->create(['name' => 'a Wow! moment']);
        $exact = Author::factory()->create(['name' => 'Wow!']);

        $results = Author::query()->search('Wow!', in: ['name'])->get();

        expect($results->pluck('id')->all())->toBe([$exact->id, $substring->id]);
    });

    it('escapes wildcards in the relevance ordering as well as the filter', function () {
        // A term escaped in the WHERE but not in the ORDER BY would match rows
        // that then all score zero, silently losing the ranking.
        $substring = Author::factory()->create(['name' => 'a 50% share']);
        $exact = Author::factory()->create(['name' => '50%']);

        $results = Author::query()->search('50%', in: ['name'])->get();

        expect($results->pluck('id')->all())->toBe([$exact->id, $substring->id]);
    });
});

describe('nested relation search', function () {
    it('searches through a multi-hop relation path', function () {
        $author = Author::factory()->create(['name' => 'Jane']);
        $needle = Post::factory()->create(['author_id' => $author->id, 'title' => 'Needle']);
        $sibling = Post::factory()->create(['author_id' => $author->id, 'title' => 'Haystack']);

        $other = Author::factory()->create(['name' => 'Bob']);
        Post::factory()->create(['author_id' => $other->id, 'title' => 'Unrelated']);

        // Post -> author -> posts -> title: posts whose author also wrote "Needle".
        $results = Post::query()->search('Needle', in: ['author.posts.title'])->get();

        expect($results->pluck('id')->sort()->values()->all())
            ->toBe([$needle->id, $sibling->id]);
    });

    it('ranks a nested relation column below an earlier column', function () {
        $jane = Author::factory()->create(['name' => 'Jane']);
        $bob = Author::factory()->create(['name' => 'Bob']);

        // Created first (lowest id) to prove the ordering is by relevance:
        // it matches only through author -> posts -> title.
        $nestedMatch = Post::factory()->create(['author_id' => $jane->id, 'title' => 'Unrelated']);
        $janeTitle = Post::factory()->create(['author_id' => $jane->id, 'title' => 'Laravel']);
        $bobTitle = Post::factory()->create(['author_id' => $bob->id, 'title' => 'Laravel']);

        $results = Post::query()->search('Laravel', in: ['title', 'author.posts.title'])->get();

        // The title matches lead; the nested-only match sinks to the bottom.
        expect($results->pluck('id')->all())
            ->toBe([$janeTitle->id, $bobTitle->id, $nestedMatch->id]);
    });
});

describe('multi-term search', function () {
    it('matches terms in any order', function () {
        Author::factory()->create(['name' => 'Jane Doe']);

        expect(Author::query()->search('Doe Jane', in: ['name'])->get())->toHaveCount(1);
    });

    it('matches terms spread across different columns', function () {
        Author::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        Author::factory()->create(['name' => 'Jane Roe', 'email' => 'roe@example.com']);

        // "Jane" matches the name, "roe@example.com" the email - neither column
        // contains both terms.
        $results = Author::query()->search('Jane roe@example.com', in: ['name', 'email'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->email)->toBe('roe@example.com');
    });

    it('requires every term to match somewhere', function () {
        Author::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        expect(Author::query()->search('Jane Nonexistent', in: ['name', 'email'])->get())->toBeEmpty();
    });

    it('keeps a double-quoted phrase as a single term', function () {
        $contiguous = Author::factory()->create(['name' => 'Jane Doe']);
        Author::factory()->create(['name' => 'Doe, Jane']);

        $results = Author::query()->search('"Jane Doe"', in: ['name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($contiguous->id);
    });

    it('mixes a quoted phrase with loose terms', function () {
        $match = Author::factory()->create(['name' => 'Jane Doe', 'email' => 'senior.a@example.com']);
        Author::factory()->create(['name' => 'Doe, Jane', 'email' => 'senior.b@example.com']);

        $results = Author::query()->search('"Jane Doe" senior', in: ['name', 'email'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($match->id);
    });

    it('does not return the whole table for a quote-only search', function () {
        Author::factory()->create(['name' => 'Jane']);
        Author::factory()->create(['name' => 'Bob']);

        // Stripping the quotes leaves no terms. Falling back to the literal
        // string keeps the WHERE in place instead of matching everything.
        expect(Author::query()->search('"', in: ['name'])->get())->toBeEmpty()
            ->and(Author::query()->search('""', in: ['name'])->get())->toBeEmpty();
    });

    it('searches for a literal zero', function () {
        $zero = Author::factory()->create(['name' => '0']);
        Author::factory()->create(['name' => 'Bob']);

        // `'0'` is falsy, so an empty() guard would have skipped the search.
        $results = Author::query()->search('0', in: ['name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($zero->id);
    });

    it('drops a stray unbalanced quote rather than searching for it', function () {
        Author::factory()->create(['name' => 'Jane Doe']);

        expect(Author::query()->search('Jane "Doe', in: ['name'])->get())->toHaveCount(1);
    });

    it('falls back to phrase matching when maxTerms is 1', function () {
        Author::factory()->create(['name' => 'Jane Doe']);

        $query = Author::query();
        $query->getModel()->applySearch($query, 'Doe Jane', in: ['name'], maxTerms: 1);

        expect($query->get())->toBeEmpty();
    });

    it('caps the number of terms it will split into', function () {
        Author::factory()->create(['name' => 'one two three']);

        // Only the first two terms survive the cap, so the unmatchable third is
        // dropped rather than expanding the query further.
        $query = Author::query();
        $query->getModel()->applySearch($query, 'one two nonexistent', in: ['name'], maxTerms: 2);

        expect($query->get())->toHaveCount(1);
    });

    it('ignores a whitespace-only search', function () {
        Author::factory()->create(['name' => 'Jane']);
        Author::factory()->create(['name' => 'Bob']);

        expect(Author::query()->search('   ', in: ['name'])->get())->toHaveCount(2);
    });

    it('collapses repeated whitespace between terms', function () {
        Author::factory()->create(['name' => 'Jane Doe']);

        expect(Author::query()->search("Jane \n\t  Doe", in: ['name'])->get())->toHaveCount(1);
    });

    it('applies every term to a relation column', function () {
        $author = Author::factory()->create(['name' => 'Jane Doe']);
        $match = Post::factory()->create(['author_id' => $author->id]);

        $partial = Author::factory()->create(['name' => 'Jane Roe']);
        Post::factory()->create(['author_id' => $partial->id]);

        $results = Post::query()->search('Jane Doe', in: ['author.name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($match->id);
    });

    it('applies every term to an external relation column', function () {
        $category = Category::factory()->create(['name' => 'Web Development']);
        $match = Post::factory()->create(['category_id' => $category->id]);

        $partial = Category::factory()->create(['name' => 'Web Design']);
        Post::factory()->create(['category_id' => $partial->id]);

        $results = Post::query()->search('Web Development', in: ['category.name'])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($match->id);
    });
});

describe('multi-term relevance ordering', function () {
    it('ranks a contiguous phrase above scattered term matches', function () {
        // Scattered match is created first (lower id) to prove the ordering.
        $scattered = Post::factory()->create(['title' => 'Guide to Laravel']);
        $phrase = Post::factory()->create(['title' => 'Laravel Guide']);

        $results = Post::query()->search('Laravel Guide', in: ['title'])->get();

        expect($results->pluck('id')->all())->toBe([$phrase->id, $scattered->id]);
    });

    it('ranks multi-term matches inside a relation column', function () {
        $scatteredAuthor = Author::factory()->create(['name' => 'Doe, Jane']);
        $scattered = Post::factory()->create(['author_id' => $scatteredAuthor->id]);

        $phraseAuthor = Author::factory()->create(['name' => 'Jane Doe']);
        $phrase = Post::factory()->create(['author_id' => $phraseAuthor->id]);

        $results = Post::query()->search('Jane Doe', in: ['author.name'])->get();

        expect($results->pluck('id')->all())->toBe([$phrase->id, $scattered->id]);
    });

    it('ranks multi-term matches inside a morph column', function () {
        $scatteredPost = Post::factory()->create(['title' => 'Guide to Laravel']);
        $scattered = Comment::factory()->create([
            'body' => 'Unrelated',
            'commentable_type' => 'post',
            'commentable_id' => $scatteredPost->id,
        ]);

        $phrasePost = Post::factory()->create(['title' => 'Laravel Guide']);
        $phrase = Comment::factory()->create([
            'body' => 'Unrelated',
            'commentable_type' => 'post',
            'commentable_id' => $phrasePost->id,
        ]);

        $results = Comment::query()
            ->search('Laravel Guide', in: ['commentable:post.title'])
            ->get();

        expect($results->pluck('id')->all())->toBe([$phrase->id, $scattered->id]);
    });
});

describe('external query efficiency', function () {
    it('queries the external connection once per term', function () {
        $category = Category::factory()->create(['name' => 'Laravel']);
        Post::factory()->create(['category_id' => $category->id]);

        DB::connection('external')->flushQueryLog();
        DB::connection('external')->enableQueryLog();

        Post::query()->search('Laravel', in: ['category.name'])->get();

        // The keys are fetched once and shared by the WHERE and the ORDER BY.
        expect(DB::connection('external')->getQueryLog())->toHaveCount(1);

        DB::connection('external')->disableQueryLog();
    });
});
