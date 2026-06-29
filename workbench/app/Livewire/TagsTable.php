<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Models\Tag;

/**
 * A table for a model that does NOT use the Searchable trait, to prove the
 * global relevance hook is a harmless no-op on other tables.
 */
class TagsTable extends TestTableComponent
{
    public function table(Table $table): Table
    {
        return $table
            ->query(Tag::query())
            ->columns([
                TextColumn::make('name')->searchable(),
            ]);
    }
}
