<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Padosoft\AgentSidebar\ContextValue;
use Padosoft\AgentSidebar\Subject;

final readonly class ContextAccess
{
    public function __construct(private Subject $subject) {}

    public function get(string $key): ?ContextValue
    {
        $entry = app(MemoryStore::class)->get($this->subject, 'context', $key);

        return $entry === null ? null : new ContextValue($entry->content, array_keys($entry->dependencies));
    }

    public function put(string $key, ContextValue $value): self
    {
        app(MemoryStore::class)->put($this->subject, 'context', $key, $value);

        return $this;
    }

    public function forget(string $key): self
    {
        app(MemoryStore::class)->forget($this->subject, 'context', $key);

        return $this;
    }
}
