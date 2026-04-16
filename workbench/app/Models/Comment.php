<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Mozex\Searchable\Searchable;
use Workbench\Database\Factories\CommentFactory;

class Comment extends Model
{
    use HasFactory;
    use Searchable;

    protected $fillable = ['body', 'commentable_type', 'commentable_id'];

    /**
     * @return array<int, string>
     */
    public function searchableColumns(): array
    {
        return [
            'body',
            'commentable:post.title',
            'commentable:post.author.name',
            'commentable:category.name',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }
}
