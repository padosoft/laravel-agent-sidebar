<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Padosoft\AgentSidebar\Contracts\Host;

class ApplicationHost implements Host
{
    public function scope(Request $request, Authenticatable $actor): ?string
    {
        return 'application';
    }

    public function page(Subject $subject, string $route, array $parameters): ?Page
    {
        $label = config('agent-sidebar.pages', [])[$route] ?? null;

        return is_string($label) ? new Page($label) : null;
    }

    public function sources(Subject $subject, array $ids): iterable
    {
        return [];
    }
}
