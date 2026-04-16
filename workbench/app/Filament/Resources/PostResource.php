<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Post;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return "https://example.test/posts/{$record->getKey()}";
    }

    /**
     * Override to expose only a subset of the model's searchable columns
     * to global search. Used in tests to verify the provider passes
     * resource attributes through as the `in:` filter.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title'];
    }
}
