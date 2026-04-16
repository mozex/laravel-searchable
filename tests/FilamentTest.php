<?php

use Filament\Tables\Columns\TextColumn;

describe('filament integration', function () {
    it('registers advancedSearchable macro on TextColumn', function () {
        expect(TextColumn::hasMacro('advancedSearchable'))->toBeTrue();
    });

    it('advancedSearchable returns TextColumn for chaining', function () {
        $column = TextColumn::make('title')->advancedSearchable();

        expect($column)->toBeInstanceOf(TextColumn::class);
    });

    it('advancedSearchable accepts in parameter', function () {
        $column = TextColumn::make('title')->advancedSearchable(in: ['title', 'body']);

        expect($column)->toBeInstanceOf(TextColumn::class);
    });

    it('advancedSearchable accepts except parameter', function () {
        $column = TextColumn::make('title')->advancedSearchable(except: 'body');

        expect($column)->toBeInstanceOf(TextColumn::class);
    });

    it('advancedSearchable accepts custom method parameter', function () {
        $column = TextColumn::make('title')->advancedSearchable(method: 'databaseSearch');

        expect($column)->toBeInstanceOf(TextColumn::class);
    });
});
