<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Filament\Tables\Table;
use Workbench\App\Filament\Tables\Columns\CustomTextColumn;
use Workbench\App\Models\Post;

class CustomPostsTable extends TestTableComponent
{
    public function table(Table $table): Table
    {
        return $table
            ->query(Post::query())
            ->columns([
                CustomTextColumn::make('title')->advancedSearchable(),
            ]);
    }
}
