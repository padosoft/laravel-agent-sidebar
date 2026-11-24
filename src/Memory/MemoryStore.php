<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Padosoft\AgentSidebar\ContextValue;
use Padosoft\AgentSidebar\Conversations;
use Padosoft\AgentSidebar\Models\MemoryEntry;
use Padosoft\AgentSidebar\Models\SidebarSession;
use Padosoft\AgentSidebar\Source;
use Padosoft\AgentSidebar\SourceAccess;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\TurnStopped;
use Throwable;

final readonly class MemoryStore
{
    public const WIKI_PREFIX = 'sidebar-wiki:';

    public function __construct(private Conversations $conversations, private WikiFiles $files) {}

    public function get(Subject $subject, string $kind, string $key): ?MemoryEntry
    {
        ContextValue::assertKey($key);

        $session = $this->conversations->current($subject);

        return $session === null ? null : $this->visible($subject, $this->query($session, $kind)->where('key', $key)->first());
    }

    /** @return list<MemoryEntry> */
    public function entries(Subject $subject, string $kind): array
    {
        $session = $this->conversations->current($subject);
        if ($session === null) {
            return [];
        }

        return $this->query($session, $kind)->orderBy('key')->get()
            ->map(fn (MemoryEntry $entry): ?MemoryEntry => $this->visible($subject, $entry))->filter()->values()->all();
    }

    private function visible(Subject $subject, ?MemoryEntry $entry): ?MemoryEntry
    {
        return $entry !== null && app(SourceAccess::class)->current($subject, $entry->dependencies) !== null ? $entry : null;
    }

    /** @return array<string, string> */
    public function dependencies(Subject $subject, array $ids): array
    {
        $sources = app(SourceAccess::class)->resolve($subject, $ids);
        if (count($sources) !== count(array_unique($ids))) {
            throw new TurnStopped('sources_changed');
        }

        return $this->flatten($subject, array_map(fn (Source $source): string => $source->revision, $sources));
    }

    /** Convert private section references to their canonical source dependencies when deriving new memory. */
    public function flatten(Subject $subject, array $dependencies): array
    {
        foreach ($dependencies as $id => $revision) {
            if (! str_starts_with($id, self::WIKI_PREFIX)) {
                continue;
            }

            $entry = $this->get($subject, 'wiki', substr($id, strlen(self::WIKI_PREFIX)));
            if ($entry === null || $entry->revision !== $revision) {
                throw new TurnStopped('sources_changed');
            }

            unset($dependencies[$id]);
            $dependencies += $entry->dependencies;
        }

        return $dependencies;
    }

    public function put(Subject $subject, string $kind, string $key, ContextValue $value): void
    {
        ContextValue::assertKey($key);
        $session = $this->conversations->ensure($subject);
        $dependencies = $this->dependencies($subject, $value->sourceIds);
        $file = $kind === 'wiki' ? $this->files->stage($subject, $value->text) : null;

        try {
            if (! $this->mutate($session, function (SidebarSession $fresh) use ($subject, $kind, $key, $value, $dependencies, $file): void {
                if (app(SourceAccess::class)->current($subject, $dependencies) === null) {
                    throw new TurnStopped('sources_changed');
                }

                $this->save($fresh, $kind, $key, $kind === 'wiki' ? mb_strcut($value->text, 0, 350) : $value->text, $dependencies, $file);
            })) {
                throw new TurnStopped('session_changed');
            }
        } catch (Throwable $exception) {
            $this->files->retire($file);

            throw $exception;
        }
    }

    public function forget(Subject $subject, string $kind, string $key): void
    {
        ContextValue::assertKey($key);

        if ($session = $this->conversations->current($subject)) {
            $this->mutate($session, function (SidebarSession $fresh) use ($kind, $key): void {
                $entry = $this->query($fresh, $kind)->where('key', $key)->first();
                if ($entry !== null) {
                    $this->files->retire($entry->file_id);
                    $entry->delete();
                }

                if ($kind === 'short') {
                    $fresh->update(['short_through' => 0]);
                }
            });
        }
    }

    public function wikiText(MemoryEntry $entry): string
    {
        return $this->files->read($entry->file_id);
    }

    /** @param Closure(SidebarSession): void $write */
    public function mutate(SidebarSession $expected, Closure $write, bool $compareVersion = false): bool
    {
        return DB::transaction(function () use ($expected, $write, $compareVersion): bool {
            SidebarSession::query()->whereKey($expected->id)->increment('write_version');
            $fresh = SidebarSession::query()->find($expected->id);
            if ($fresh === null || $fresh->public_id !== $expected->public_id
                || ($compareVersion && $fresh->memory_version !== $expected->memory_version)) {
                return false;
            }

            $write($fresh);
            $fresh->increment('memory_version');

            return true;
        }, attempts: 5);
    }

    /** Must run inside a successful session mutation. */
    public function save(SidebarSession $session, string $kind, string $key, string $text, array $dependencies, ?int $file = null, bool $preserveRevision = false): void
    {
        $query = $this->query($session, $kind);
        $old = (clone $query)->where('key', $key)->first();
        if ($file !== null) {
            $this->files->publish($file);
        }

        $revision = $preserveRevision && $old !== null && $old->dependencies == $dependencies
            ? $old->revision
            : (string) Str::uuid();

        $query->updateOrCreate(['session_id' => $session->id, 'kind' => $kind, 'key' => $key], [
            'revision' => $revision, 'content' => $text, 'dependencies' => $dependencies, 'file_id' => $file,
        ]);
        $this->files->retire($old?->file_id);
    }

    private function query(SidebarSession $session, string $kind): Builder
    {
        return MemoryEntry::query()->where('session_id', $session->id)->where('kind', $kind);
    }
}
