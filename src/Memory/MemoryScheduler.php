<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Illuminate\Http\Request;
use Padosoft\AgentSidebar\Conversations;
use Padosoft\AgentSidebar\Models\SidebarSession;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\Subject;

final readonly class MemoryScheduler
{
    public function __construct(private Conversations $conversations, private MemoryStore $store) {}

    public function afterTurn(Request $request, Subject $subject, SidebarSession $session, int $turnId, ModelSelection $model, bool $rebuildShort = false): void
    {
        $authority = config('agent-sidebar.memory.authorizer');
        if (! config('agent-sidebar.memory.enabled') || $authority === null) {
            return;
        }

        $model = config('agent-sidebar.memory.model') ?? $model;
        $grant = app($authority)->capture($request, $subject, $model);
        if ($grant === null) {
            return;
        }

        if ($this->store->mutate($session, function (SidebarSession $fresh) use ($turnId, $model, $grant, $rebuildShort): void {
            $fresh->update([
                'memory_pending' => max($turnId, $fresh->memory_pending),
                'short_rebuild' => $rebuildShort || $fresh->short_rebuild,
                'memory_permit' => ['grant' => $grant, 'provider' => $model->provider, 'model' => $model->model],
            ]);
        })) {
            $this->dispatch($session);
        }
    }

    public function rebuild(Request $request, Subject $subject, ModelSelection $model): void
    {
        $session = $this->conversations->current($subject);
        $latest = $session?->turns()->where('status', 'completed')->max('id');
        if ($latest !== null) {
            $this->afterTurn($request, $subject, $session, (int) $latest, $model, rebuildShort: true);
        }
    }

    public function dispatch(SidebarSession $session): void
    {
        $connection = config('agent-sidebar.memory.queue_connection') ?? config('queue.default');
        UpdateMemory::dispatch($session->id, $session->public_id)->onConnection($connection)->afterCommit();
    }

    public function maintain(WikiFiles $files): void
    {
        $files->cleanup();
        if (! config('agent-sidebar.memory.enabled')) {
            return;
        }

        SidebarSession::query()->whereNotNull('memory_permit')
            ->where(fn ($query) => $query->whereColumn('memory_processed', '<', 'memory_pending')->orWhere('short_rebuild', true))
            ->limit(100)->get()->each(fn (SidebarSession $session) => $this->dispatch($session));
    }
}
