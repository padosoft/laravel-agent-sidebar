<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Padosoft\AgentSidebar\Contracts\ReadTool;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\ToolResult;

final readonly class ReadWikiSection implements ReadTool
{
    public function __construct(private MemoryStore $store) {}

    public function name(): string
    {
        return 'read_wiki_section';
    }

    public function description(): string
    {
        return 'Read a selected section of the current user’s private long-term memory by key.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['key' => $schema->string()->enum(config('agent-sidebar.memory.sections', []))->required()];
    }

    public function rules(): array
    {
        return ['key' => ['required', 'string', Rule::in(config('agent-sidebar.memory.sections', []))]];
    }

    public function read(Subject $subject, array $arguments): ToolResult
    {
        $key = $arguments['key'];
        $entry = $this->store->get($subject, 'wiki', $key);

        $id = MemoryStore::WIKI_PREFIX.$key;

        return $entry === null
            ? new ToolResult('This section is unavailable.')
            : new ToolResult(mb_strcut($this->store->wikiText($entry), 0, 12000), [$id], citeable: false, revisions: [$id => $entry->revision]);
    }
}
