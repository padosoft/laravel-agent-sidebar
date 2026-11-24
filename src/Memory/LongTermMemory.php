<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Padosoft\AgentSidebar\ContextValue;
use Padosoft\AgentSidebar\Models\MemoryEntry;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;

final readonly class LongTermMemory
{
    public function __construct(private Subject $subject) {}

    public function summary(): string
    {
        return app(MemoryPrompt::class)->summary($this->subject, ModelSelection::resolve($this->subject));
    }

    /** @return list<string> */
    public function sections(): array
    {
        return array_map(fn (MemoryEntry $entry): string => $entry->key, app(MemoryStore::class)->entries($this->subject, 'wiki'));
    }

    public function section(string $key): WikiSection
    {
        ContextValue::assertKey($key);

        return new WikiSection($this->subject, $key);
    }
}
