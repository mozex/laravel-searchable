<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mozex\Searchable\Searchable;
use Workbench\Database\Factories\AuthorFactory;

class Author extends Model
{
    use HasFactory;
    use Searchable;

    protected $fillable = ['name', 'email'];

    /**
     * @return array<int, string>
     */
    public function searchableColumns(): array
    {
        return ['name', 'email', 'posts.title'];
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    protected static function newFactory(): AuthorFactory
    {
        return AuthorFactory::new();
    }
}
