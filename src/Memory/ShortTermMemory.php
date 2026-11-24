<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Illuminate\Http\Request;
use Padosoft\AgentSidebar\ContextValue;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;

final readonly class ShortTermMemory
{
    public function __construct(private Request $request, private Subject $subject) {}

    public function get(): ?ContextValue
    {
        $entry = app(MemoryStore::class)->get($this->subject, 'short', 'summary');

        return $entry === null ? null : new ContextValue($entry->content, array_keys($entry->dependencies));
    }

    public function replace(ContextValue $value): self
    {
        app(MemoryStore::class)->put($this->subject, 'short', 'summary', $value);

        return $this;
    }

    public function clear(): self
    {
        app(MemoryStore::class)->forget($this->subject, 'short', 'summary');

        return $this;
    }

    public function rebuild(): self
    {
        app(MemoryScheduler::class)->rebuild($this->request, $this->subject, ModelSelection::resolve($this->subject));

        return $this;
    }
}
