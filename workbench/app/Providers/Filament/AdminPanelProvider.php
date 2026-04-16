<?php

declare(strict_types=1);

namespace Workbench\App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Workbench\App\Filament\Resources\AuthorResource;
use Workbench\App\Filament\Resources\PostResource;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([
                AuthorResource::class,
                PostResource::class,
            ]);
    }
}
