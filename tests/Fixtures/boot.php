<?php

/*
 * Boots a fresh app in its own process and reports which Filament classes the
 * boot loaded. A test process can't answer that: earlier tests have already
 * loaded every table class. With --without-filament, Composer can no longer
 * find any Filament class, as in an app that never installed it.
 */

use Composer\Autoload\ClassLoader;
use Filament\Support\Components\ComponentManager;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Mozex\Searchable\SearchableServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$withoutFilament = in_array('--without-filament', $argv, true);

if ($withoutFilament) {
    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        foreach (array_keys($loader->getPrefixesPsr4()) as $prefix) {
            if (str_starts_with($prefix, 'Filament\\')) {
                $loader->setPsr4($prefix, []);
            }
        }

        // An optimized autoloader lists every class in its class map too,
        // which ClassLoader offers no public way to remove from.
        (function (): void {
            $this->classMap = array_filter(
                $this->classMap,
                fn (string $class): bool => ! str_starts_with($class, 'Filament\\'),
                ARRAY_FILTER_USE_KEY,
            );
        })->call($loader);
    }
}

$app = $withoutFilament
    ? Application::create(options: ['extra' => ['providers' => [SearchableServiceProvider::class], 'dont-discover' => ['*']]])
    : Application::createFromConfig(Config::loadFromYaml($root));

$loaded = fn (): array => [
    'Table' => class_exists(Table::class, false),
    'Column' => class_exists(Column::class, false),
    'TextColumn' => class_exists(TextColumn::class, false),
    'ComponentManager' => class_exists(ComponentManager::class, false),
];

$report = [
    'booted' => $app->isBooted() && $app->providerIsLoaded(SearchableServiceProvider::class),
    'loaded' => $loaded(),
];

if (! $withoutFilament) {
    $report['configured'] = array_keys((fn (): array => $this->configurations)->call(ComponentManager::resolve()));
    $report['macroBeforeColumn'] = TextColumn::hasMacro('advancedSearchable');
    $report['searchableColumn'] = TextColumn::make('title')->advancedSearchable()->isSearchable();
}

echo json_encode($report);
