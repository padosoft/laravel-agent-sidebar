<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Pact;

use Closure;
use PactLaravel\Execution\AgentContext;
use PactLaravel\Execution\AgentResult;
use Padosoft\AgentSidebar\AuthorizedRun;

interface PactAuthorization
{
    /**
     * Resolve the task's authority and claim its invocation in the transport checkpoint
     * before invoking the callback. The run may reserve each step through the coordinator.
     *
     * @param  Closure(AuthorizedRun): AgentResult  $work
     */
    public function execute(AgentContext $task, Closure $work): AgentResult;
}
