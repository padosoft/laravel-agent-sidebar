<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Contracts;

use Padosoft\AgentSidebar\Subject;

interface ToolAuthorization
{
    public function allowsTool(Subject $subject, string $tool, string $channel): bool;
}
