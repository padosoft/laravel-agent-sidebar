<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Tests;

use Padosoft\AgentSidebar\Contracts\ModelResolver;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;

final class TestModelResolver implements ModelResolver
{
    public function resolve(Subject $subject): ?ModelSelection
    {
        return $subject->scope === 'workspace:acme' ? new ModelSelection('sidebar-test', 'resolved-acme') : null;
    }
}
