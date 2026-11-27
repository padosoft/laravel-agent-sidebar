<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class AgentSidebar
{
    private const ROUTE_PREFIX = 'agent-sidebar/v1';

    private const ROUTE_NAME_PREFIX = 'agent-sidebar.';

    public static function configure(): SidebarConfiguration
    {
        return new SidebarConfiguration;
    }

    public static function forRequest(Request $request): SidebarAccess
    {
        return new SidebarAccess($request);
    }

    public static function routes(string $prefix = self::ROUTE_PREFIX, string $name = self::ROUTE_NAME_PREFIX): void
    {
        $prefix = trim(trim($prefix), '/');
        $name = trim($name);

        Route::middleware([PrivateJson::class, 'web', 'auth'])
            ->prefix($prefix === '' ? self::ROUTE_PREFIX : $prefix)
            ->name($name === '' ? self::ROUTE_NAME_PREFIX : $name)
            ->group(function (): void {
                Route::get('session', [SidebarController::class, 'show'])->name('session.show');
                Route::post('session/turns', [SidebarController::class, 'store'])->name('turns.store');
                Route::get('session/turns/{clientMessageId}', [SidebarController::class, 'turn'])->whereUuid('clientMessageId')->name('turns.show');
                Route::delete('session/{sessionId}', [SidebarController::class, 'destroy'])->whereUuid('sessionId')->name('session.destroy');
            });
    }
}
