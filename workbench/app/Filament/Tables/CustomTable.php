<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Tables;

use Filament\Tables\Table;

/**
 * An app's own Table subclass, bound in the container in place of Table. The
 * relevance sort is registered for Table by class name and must reach it.
 */
class CustomTable extends Table {}
