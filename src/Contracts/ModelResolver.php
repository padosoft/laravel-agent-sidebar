<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Contracts;

use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;

interface ModelResolver
{
    public function resolve(Subject $subject): ?ModelSelection;
}
