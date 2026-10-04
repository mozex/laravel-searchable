<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

const STAR_QUESTION = 'Would you like to show some love by starring laravel-searchable on GitHub?';

beforeEach(function (): void {
    Process::fake();

    @unlink($this->app->configPath('searchable.php'));
});

afterEach(function (): void {
    @unlink($this->app->configPath('searchable.php'));
});

function assertOpenedRepository(): void
{
    Process::assertRan(
        fn (PendingProcess $process): bool => in_array('https://github.com/mozex/laravel-searchable', (array) $process->command, true)
    );
}

it('publishes the config file', function (): void {
    $this->artisan('searchable:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('searchable.php')))
        ->toBe(file_get_contents(__DIR__.'/../config/searchable.php'));
});

it('leaves an existing config file alone', function (): void {
    file_put_contents($this->app->configPath('searchable.php'), '<?php return [];');

    $this->artisan('searchable:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('searchable.php')))->toBe('<?php return [];');
});

it('opens the repository when the user agrees to star it', function (): void {
    $this->artisan('searchable:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->doesntExpectOutputToContain('please consider starring')
        ->doesntExpectOutputToContain("You'll find")
        ->assertSuccessful();

    assertOpenedRepository();
});

it('opens nothing when the user declines', function (): void {
    $this->artisan('searchable:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('prints the repository link when the browser cannot be opened', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('searchable:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find laravel-searchable at https://github.com/mozex/laravel-searchable")
        ->assertSuccessful();
});

it('prints the repository link when opening the browser throws', function (): void {
    Process::fake(fn () => throw new RuntimeException('No opener.'));

    $this->artisan('searchable:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find laravel-searchable at https://github.com/mozex/laravel-searchable")
        ->assertSuccessful();
});

it('opens the repository with a note instead of asking when nobody can answer', function (): void {
    $this->artisan('searchable:install', ['--no-interaction' => true])
        ->expectsOutputToContain('If laravel-searchable saves you time, please consider starring it on GitHub: https://github.com/mozex/laravel-searchable')
        ->assertSuccessful();

    assertOpenedRepository();

    expect($this->app->configPath('searchable.php'))->toBeFile();
});
