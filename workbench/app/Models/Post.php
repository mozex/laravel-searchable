<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mozex\Searchable\Searchable;
use Workbench\Database\Factories\PostFactory;

class Post extends Model
{
    use HasFactory;
    use Searchable;

    protected $fillable = ['title', 'body', 'author_id', 'category_id'];

    /**
     * @return array<int, string>
     */
    public function searchableColumns(): array
    {
        return ['title', 'body', 'author.name', 'category.name'];
    }

    /** @return BelongsTo<Author, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }
}
