<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class AgentSidebar
{
    public static function configure(): SidebarConfiguration
    {
        return new SidebarConfiguration;
    }

    public static function forRequest(Request $request): SidebarAccess
    {
        return new SidebarAccess($request);
    }

    public static function routes(string $prefix = 'agent-sidebar/v1', string $name = 'agent-sidebar.'): void
    {
        Route::middleware([PrivateJson::class, 'web', 'auth'])->prefix($prefix)->name($name)->group(function (): void {
            Route::get('session', [SidebarController::class, 'show'])->name('session.show');
            Route::post('session/turns', [SidebarController::class, 'store'])->name('turns.store');
            Route::get('session/turns/{clientMessageId}', [SidebarController::class, 'turn'])->whereUuid('clientMessageId')->name('turns.show');
            Route::delete('session/{sessionId}', [SidebarController::class, 'destroy'])->whereUuid('sessionId')->name('session.destroy');
        });
    }
}
