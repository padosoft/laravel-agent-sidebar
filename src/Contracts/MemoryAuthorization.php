<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Contracts;

use Closure;
use Illuminate\Http\Request;
use Padosoft\AgentSidebar\AuthorizedRun;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;

interface MemoryAuthorization
{
    /** Return a serializable grant reference with no credentials. Null skips background inference. */
    public function capture(Request $request, Subject $subject, ModelSelection $model): ?array;

    /**
     * Rehydrate current identity, scope and provider authorization for the worker, and restore host state in finally.
     * Invoke the callback only while the permit is valid and permits the selected model and destination.
     *
     * @param  Closure(AuthorizedRun): void  $work
     */
    public function run(array $permit, ModelSelection $model, Closure $work): void;
}
