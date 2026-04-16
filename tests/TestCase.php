<?php

declare(strict_types=1);

namespace Mozex\Searchable\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\Category;
use Workbench\App\Models\Post;

class TestCase extends Orchestra
{
    use WithWorkbench;

    /** @var array<int, string> */
    protected $connectionsToTransact = ['testing', 'external'];

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap([
            'post' => Post::class,
            'category' => Category::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $app['config']->set('database.connections.external', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }
}
