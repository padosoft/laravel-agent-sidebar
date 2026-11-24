<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Padosoft\AgentSidebar\Contracts\Host;
use Padosoft\AgentSidebar\Memory\MemoryStore;
use Padosoft\AgentSidebar\Models\SidebarTurn;

final readonly class SourceAccess
{
    public function __construct(private Host $host) {}

    /**
     * @param  list<string>  $ids
     * @return array<string, Source>
     */
    public function resolve(Subject $subject, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $allowed = array_fill_keys($ids, true);
        $sources = [];
        foreach (array_keys($allowed) as $id) {
            if (! str_starts_with($id, MemoryStore::WIKI_PREFIX)) {
                continue;
            }

            unset($allowed[$id]);
            $key = substr($id, strlen(MemoryStore::WIKI_PREFIX));
            $entry = in_array($key, config('agent-sidebar.memory.sections', []), true)
                ? app(MemoryStore::class)->get($subject, 'wiki', $key)
                : null;
            if ($entry !== null) {
                $sources[$id] = new Source($id, $entry->key, '/', $entry->revision);
            }
        }
        foreach ($allowed === [] ? [] : $this->host->sources($subject, array_keys($allowed)) as $source) {
            if (isset($allowed[$source->id])) {
                $sources[$source->id] = $source;
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, string>  $dependencies
     * @return array<string, Source>|null
     */
    public function current(Subject $subject, array $dependencies): ?array
    {
        $sources = $this->resolve($subject, array_keys($dependencies));

        foreach ($dependencies as $id => $revision) {
            if (! isset($sources[$id]) || $sources[$id]->revision !== $revision) {
                return null;
            }
        }

        return $sources;
    }

    /** @return array<string, mixed> */
    public function turn(Subject $subject, SidebarTurn $turn): array
    {
        $sources = $this->current($subject, $turn->dependencies ?? []);
        $citations = array_intersect_key($sources ?? [], array_fill_keys($turn->citation_ids ?? [], true));

        return [
            'id' => $turn->client_message_id,
            'message' => $turn->question,
            'status' => $turn->status,
            'available' => $sources !== null,
            'answer' => $sources === null ? null : $turn->answer,
            'sources' => array_values(array_map(fn (Source $source): array => $source->citation(), $citations)),
            'error_code' => $turn->error_code,
            'created_at' => $turn->created_at->toISOString(),
        ];
    }
}
