<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Support\ServiceProvider;
use Padosoft\AgentSidebar\Contracts\Host;

final class AgentSidebarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-sidebar.php', 'agent-sidebar');
        $this->app->bind(Host::class, fn ($app): Host => $app->make(config('agent-sidebar.host')));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/agent-sidebar.php' => config_path('agent-sidebar.php'),
        ], 'agent-sidebar-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'agent-sidebar-migrations');
    }
}
