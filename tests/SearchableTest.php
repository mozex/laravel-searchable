<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\Author;
use Workbench\App\Models\Category;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;

uses(RefreshDatabase::class);

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
