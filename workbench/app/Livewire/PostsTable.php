<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Models\Post;

class PostsTable extends TestTableComponent
{
    public bool $useDefaultSort = false;

    public function mount(bool $useDefaultSort = false): void
    {
        $this->useDefaultSort = $useDefaultSort;
    }

    public function table(Table $table): Table
    {
        $table
            ->query(Post::query())
            ->columns([
                TextColumn::make('title')->advancedSearchable()->sortable(),
            ]);

        if ($this->useDefaultSort) {
            $table->defaultSort('id', 'desc');
        }

        return $table;
    }
}
