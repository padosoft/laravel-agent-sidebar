<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Padosoft\AgentSidebar\ContextValue;
use Padosoft\AgentSidebar\Subject;

final readonly class WikiSection
{
    public function __construct(private Subject $subject, private string $key) {}

    public function get(): ?ContextValue
    {
        $store = app(MemoryStore::class);
        $entry = $store->get($this->subject, 'wiki', $this->key);

        return $entry === null ? null : new ContextValue($store->wikiText($entry), array_keys($entry->dependencies));
    }

    public function put(ContextValue $value): self
    {
        app(MemoryStore::class)->put($this->subject, 'wiki', $this->key, $value);

        return $this;
    }

    public function forget(): self
    {
        app(MemoryStore::class)->forget($this->subject, 'wiki', $this->key);

        return $this;
    }
}
