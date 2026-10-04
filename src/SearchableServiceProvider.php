<?php

declare(strict_types=1);

namespace Mozex\Searchable;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Mozex\Searchable\Filament\RelevanceSort;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class SearchableServiceProvider extends PackageServiceProvider
{
    protected string $repository = 'https://github.com/mozex/laravel-searchable';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-searchable')
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->endWith(fn (InstallCommand $command) => $this->askToStar($command));
            });
    }

    /**
     * A person gets the question, defaulting to yes. A run nobody can answer
     * (--no-interaction, or no terminal on stdin, as with CI and AI agents)
     * takes that default without asking, so it gets a note explaining the
     * browser tab instead.
     */
    protected function askToStar(InstallCommand $command): void
    {
        if (! $this->isInteractive($command)) {
            $command->info('If laravel-searchable saves you time, please consider starring it on GitHub: '.$this->repository);

            $this->openInBrowser();

            return;
        }

        if (! $command->confirm('Would you like to show some love by starring laravel-searchable on GitHub?', true)) {
            return;
        }

        if ($this->openInBrowser()) {
            return;
        }

        $command->info("You'll find laravel-searchable at ".$this->repository);
    }

    /**
     * Laravel's own rule for prompts (stdin must be a terminal, except under
     * unit tests, where the console output is faked), except that
     * --no-interaction always wins, which keeps that path testable.
     */
    protected function isInteractive(InstallCommand $command): bool
    {
        if ($command->option('no-interaction') === true) {
            return false;
        }

        if ($this->app->runningUnitTests()) {
            return true;
        }

        return defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Best effort: any failure returns false. On Linux the opener runs in the
     * background, because xdg-open without a detected desktop runs the browser
     * in the foreground and would hold the command until the browser closes.
     */
    protected function openInBrowser(): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $this->repository],
            'Windows' => ['cmd', '/c', 'start', '', $this->repository],
            default => ['sh', '-c', 'command -v xdg-open > /dev/null && (xdg-open "$1" > /dev/null 2>&1 &)', 'sh', $this->repository],
        };

        try {
            return Process::run($command)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function packageBooted(): void
    {
        $this->registerFilamentMacros();
        $this->registerFilamentRelevanceSort();
    }

    protected function registerFilamentRelevanceSort(): void
    {
        if (! class_exists(Table::class)) {
            return;
        }

        RelevanceSort::register();
    }

    protected function registerFilamentMacros(): void
    {
        if (! class_exists(TextColumn::class)) {
            return;
        }

        // Builds the search WHERE only. Filament runs this inside a nested
        // WHERE closure, so relevance ordering can't ride along here (the
        // orderBy would be discarded). Ranking is applied separately and
        // automatically by the global RelevanceSort query scope (registered in
        // registerFilamentRelevanceSort).
        TextColumn::macro('advancedSearchable', function (
            array|string $in = [],
            array|string $include = [],
            array|string $except = [],
            ?int $externalLimit = null,
            string $method = 'search',
            ?int $maxTerms = null
        ) {
            // Resolved here rather than passed on as null: $method may name an
            // aliased scope or a user's own method that still types these as int.
            $externalLimit ??= (int) Config::get('searchable.external_limit', 50);
            $maxTerms ??= (int) Config::get('searchable.max_terms', 10);

            $this->searchable( // @phpstan-ignore method.notFound
                query: function (Builder $query, string $search) use ($in, $include, $except, $externalLimit, $method, $maxTerms): void {
                    $query->{$method}(
                        search: $search,
                        in: $in,
                        include: $include,
                        except: $except,
                        externalLimit: $externalLimit,
                        orderByRelevance: false,
                        maxTerms: $maxTerms,
                    );
                }
            );

            return $this;
        });
    }
}
