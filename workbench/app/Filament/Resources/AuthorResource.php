<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Author;

class AuthorResource extends Resource
{
    protected static ?string $model = Author::class;

    /**
     * Return the model's full searchable columns. Used in tests to verify the
     * provider correctly searches across all columns the trait knows about.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return (new Author)->searchableColumns();
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return "https://example.test/authors/{$record->getKey()}";
    }
}
