<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Padosoft\AgentSidebar\Models\MemoryEntry;
use Padosoft\AgentSidebar\Models\SidebarSession;
use Padosoft\AgentSidebar\Models\SidebarTurn;

final class Conversations
{
    public function current(Subject $subject): ?SidebarSession
    {
        return $this->ownedBy($subject)->first();
    }

    public function ensure(Subject $subject): SidebarSession
    {
        $this->createIfMissing($subject, (string) Str::uuid());

        return $this->ownedBy($subject)->firstOrFail();
    }

    private function createIfMissing(Subject $subject, string $publicId): void
    {
        SidebarSession::query()->insertOrIgnore([
            'public_id' => $publicId,
            'actor_key' => $subject->actorKey,
            'scope_key' => $subject->scopeKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  (Closure(): void)|null  $beforeCreate
     * @return array{SidebarSession, SidebarTurn, bool}
     */
    public function reserve(Subject $subject, ?string $publicId, string $clientId, string $message, ?Closure $beforeCreate = null): array
    {
        return DB::transaction(function () use ($subject, $publicId, $clientId, $message, $beforeCreate): array {
            $newSessionId = (string) Str::uuid();
            if ($publicId === null) {
                $this->createIfMissing($subject, $newSessionId);
            }

            // Acquire the database write lock before reading competing turns, also on SQLite.
            $this->ownedBy($subject)->increment('write_version');
            $session = $this->current($subject);
            if ($session === null || ($publicId !== null && $session->public_id !== $publicId)) {
                $this->conflict('session_changed');
            }

            $this->expire($session);
            $existing = $session->turns()->where('client_message_id', $clientId)->first();
            if ($existing !== null) {
                if ($existing->question !== $message) {
                    $this->conflict('message_id_reused');
                }

                return [$session, $existing, false];
            }

            if ($session->turns()->where('active_slot', 1)->exists()) {
                $this->conflict('turn_in_progress');
            }

            if ($publicId === null && $session->public_id !== $newSessionId
                && ($session->generation !== 0 || $session->turns()->exists())) {
                $this->conflict('session_changed');
            }

            $beforeCreate?->__invoke();

            $turn = $session->turns()->create([
                'client_message_id' => $clientId,
                'question' => $message,
                'status' => 'processing',
                'active_slot' => 1,
                'expires_at' => now()->addSeconds((int) config('agent-sidebar.turn_ttl')),
            ]);

            return [$session, $turn, true];
        }, attempts: 5);
    }

    public function expire(SidebarSession $session): void
    {
        $session->turns()->where('active_slot', 1)->where('expires_at', '<=', now())->update([
            'status' => 'failed',
            'error_code' => 'turn_expired',
            'active_slot' => null,
        ]);
    }

    /**
     * @param  array<string, string>  $dependencies
     * @param  list<string>  $citations
     */
    public function complete(SidebarTurn $turn, string $answer, array $dependencies, array $citations): bool
    {
        $turn->forceFill([
            'answer' => $answer,
            'dependencies' => $dependencies,
            'citation_ids' => $citations,
            'status' => 'completed',
            'active_slot' => null,
            'updated_at' => now(),
        ]);

        return SidebarTurn::query()->whereKey($turn->getKey())->where('active_slot', 1)
            ->where('expires_at', '>', now())->update($turn->getDirty()) === 1;
    }

    public function fail(SidebarTurn $turn, string $code): void
    {
        SidebarTurn::query()->whereKey($turn->getKey())->where('active_slot', 1)->update([
            'status' => 'failed',
            'error_code' => $turn->expires_at->lessThanOrEqualTo(now()) ? 'turn_expired' : $code,
            'active_slot' => null,
        ]);
    }

    public function reset(Subject $subject, string $publicId): void
    {
        DB::transaction(function () use ($subject, $publicId): void {
            $this->ownedBy($subject)->where('public_id', $publicId)->increment('write_version');
            $session = $this->ownedBy($subject)->where('public_id', $publicId)->firstOrFail();

            $this->expire($session);
            if ($session->turns()->where('active_slot', 1)->exists()) {
                $this->conflict('turn_in_progress');
            }

            $session->turns()->delete();
            MemoryEntry::query()->where('session_id', $session->id)->whereIn('kind', ['short', 'context'])->delete();
            $session->update([
                'public_id' => (string) Str::uuid(),
                'generation' => $session->generation + 1,
                'memory_pending' => 0, 'memory_processed' => 0, 'short_through' => 0, 'short_rebuild' => false, 'memory_permit' => null,
            ]);
        }, attempts: 5);
    }

    public function forgetActor(Authenticatable $actor): void
    {
        $this->forget('actor_key', Subject::keyFor($actor));
    }

    public function forgetScope(string $scope): void
    {
        $this->forget('scope_key', hash('sha256', $scope));
    }

    private function forget(string $column, string $key): void
    {
        DB::transaction(function () use ($column, $key): void {
            $sessions = SidebarSession::query()->where($column, $key);
            $sessions->increment('write_version');

            $files = MemoryEntry::query()
                ->whereIn('session_id', (clone $sessions)->select('id'))->whereNotNull('file_id')->select('file_id');
            DB::table('agent_sidebar_wiki_files')->whereIn('id', $files)->update(['status' => 'delete', 'updated_at' => now()]);
            $sessions->delete();
        }, attempts: 5);
    }

    /** @return Builder<SidebarSession> */
    private function ownedBy(Subject $subject): Builder
    {
        return SidebarSession::query()->where('actor_key', $subject->actorKey)->where('scope_key', $subject->scopeKey);
    }

    private function conflict(string $code): never
    {
        abort(response()->json(['code' => $code], 409));
    }
}
