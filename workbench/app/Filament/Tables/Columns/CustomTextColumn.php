<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

/**
 * An app's own TextColumn subclass. The advancedSearchable macro is registered
 * when a column is first built and must be in place on it too.
 */
class CustomTextColumn extends TextColumn {}
