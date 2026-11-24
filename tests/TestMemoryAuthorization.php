<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Tests;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Padosoft\AgentSidebar\AuthorizedRun;
use Padosoft\AgentSidebar\Contracts\MemoryAuthorization;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;

final class TestMemoryAuthorization implements MemoryAuthorization
{
    public function capture(Request $request, Subject $subject, ModelSelection $model): ?array
    {
        return ['actor' => $subject->actor->getAuthIdentifier(), 'scope' => $subject->scope];
    }

    public function run(array $permit, ModelSelection $model, Closure $work): void
    {
        $work(new AuthorizedRun(new Subject(new GenericUser(['id' => $permit['actor']]), $permit['scope']), static function (): void {}));
    }
}
