<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Padosoft\AgentSidebar\ApplicationHost;
use Padosoft\AgentSidebar\Page;
use Padosoft\AgentSidebar\Source;
use Padosoft\AgentSidebar\Subject;

final class TestHost extends ApplicationHost
{
    public bool $allowSubject = true;

    public bool $allowPage = true;

    public bool $allowSource = true;

    public function scope(Request $request, Authenticatable $actor): ?string
    {
        return $this->allowSubject ? 'workspace:'.$request->route('workspace') : null;
    }

    public function page(Subject $subject, string $route, array $parameters): ?Page
    {
        return $this->allowPage && $route === 'fixture.page'
            ? new Page('Orders', 'Consult order data using the registered tool.')
            : null;
    }

    public function sources(Subject $subject, array $ids): iterable
    {
        if ($this->allowSource && in_array('order:1', $ids, true)) {
            yield new Source('order:1', 'Order 1', '/orders/1', 'v1');
        }
    }
}
