<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Padosoft\AgentSidebar\Contracts\Host;
use Padosoft\AgentSidebar\Memory\MemoryScheduler;
use Padosoft\AgentSidebar\Memory\WikiFiles;

final class AgentSidebarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-sidebar.php', 'agent-sidebar');
        $this->app->bind(Host::class, fn ($app): Host => $app->make(config('agent-sidebar.host')));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->call(function (): void {
                    if (Schema::hasTable('agent_sidebar_wiki_files')) {
                        app(MemoryScheduler::class)->maintain(app(WikiFiles::class));
                    }
                })->name('agent-sidebar-memory')->everyMinute()->withoutOverlapping();
            });
        }

        $this->publishes([
            __DIR__.'/../config/agent-sidebar.php' => config_path('agent-sidebar.php'),
        ], 'agent-sidebar-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'agent-sidebar-migrations');
    }
}
