<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Padosoft\AgentSidebar\Models\SidebarSession;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\TurnContext;

final readonly class MemoryPrompt
{
    public function __construct(private MemoryStore $store, private HistoryWindow $history, private TokenBudget $budget) {}

    /** @return array{list, array<string, mixed>} */
    public function prepare(SidebarSession $session, TurnContext $context, ModelSelection $model): array
    {
        $extra = [];
        $size = 0;
        foreach ($this->store->entries($context->subject, 'context') as $entry) {
            $size += strlen($entry->content);
            if ($size > 6000) {
                break;
            }

            if ($context->includeDependencies($entry->dependencies)) {
                $extra['session_context'][$entry->key] = $entry->content;
            }
        }

        if (! config('agent-sidebar.memory.enabled')) {
            return [$context->history($session), $extra];
        }

        $after = 0;
        $short = $this->store->get($context->subject, 'short', 'summary');
        if ($short !== null && $context->includeDependencies($short->dependencies)) {
            $after = (int) $session->short_through;
            $extra['short_memory'] = $this->budget->fit($short->content, (int) config('agent-sidebar.memory.short_tokens', 700), $model);
        }

        [$history] = $this->history->recent($session, $context, $model, $after);
        $extra['long_memory'] = $this->summary($context->subject, $model, $context);

        return [$history, $extra];
    }

    public function summary(Subject $subject, ModelSelection $model, ?TurnContext $context = null): string
    {
        $remaining = (int) config('agent-sidebar.memory.long_tokens', 500);
        $lines = [];
        foreach (config('agent-sidebar.memory.sections', []) as $key) {
            $entry = $this->store->get($subject, 'wiki', $key);
            if ($entry === null) {
                continue;
            }

            $line = $this->budget->fit($key.': '.$entry->content."\n", $remaining, $model);
            if ($line === '') {
                break;
            }

            if ($context !== null && ! $context->includeDependencies([MemoryStore::WIKI_PREFIX.$key => $entry->revision])) {
                continue;
            }

            $lines[] = $line;
            $remaining -= $this->budget->count($line, $model);
        }

        return implode('', $lines);
    }
}
