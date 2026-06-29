<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\MessageBag;
use Livewire\Component;

/**
 * Base for the real Filament table components used to exercise the automatic
 * relevance sort through Filament's actual render/query pipeline in tests.
 */
abstract class TestTableComponent extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function render(): string
    {
        return '<div></div>';
    }

    /**
     * Headless Livewire renders skip the middleware that seeds the validation
     * error bag, leaving it null and breaking the render hook. Return an empty
     * bag so the component renders in tests. Irrelevant to table ordering.
     */
    public function getErrorBag(): MessageBag
    {
        return new MessageBag;
    }
}
