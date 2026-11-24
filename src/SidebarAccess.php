<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\Request;
use Padosoft\AgentSidebar\Memory\ContextAccess;
use Padosoft\AgentSidebar\Memory\LongTermMemory;
use Padosoft\AgentSidebar\Memory\ShortTermMemory;

final readonly class SidebarAccess
{
    public function __construct(private Request $request)
    {
        $this->subject();
    }

    public function context(): ContextAccess
    {
        return new ContextAccess($this->subject());
    }

    public function shortTerm(): ShortTermMemory
    {
        return new ShortTermMemory($this->request, $this->subject());
    }

    public function longTerm(): LongTermMemory
    {
        return new LongTermMemory($this->subject());
    }

    public function turn(): TurnBuilder
    {
        return new TurnBuilder($this->request);
    }

    private function subject(): Subject
    {
        return app(PageContext::class)->subject($this->request) ?? abort(403);
    }
}
