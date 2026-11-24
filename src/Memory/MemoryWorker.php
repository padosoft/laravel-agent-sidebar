<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Illuminate\Support\Facades\Validator;
use Padosoft\AgentSidebar\AuthorizedRun;
use Padosoft\AgentSidebar\Models\MemoryEntry;
use Padosoft\AgentSidebar\Models\SidebarSession;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\SourceAccess;
use Padosoft\AgentSidebar\TurnContext;
use Padosoft\AgentSidebar\TurnStopped;

final readonly class MemoryWorker
{
    public function __construct(
        private MemoryStore $store,
        private WikiFiles $files,
        private HistoryWindow $history,
        private TokenBudget $budget,
        private SourceAccess $sources,
        private MemoryScheduler $scheduler,
    ) {}

    public function update(int $id, string $generation): void
    {
        $session = SidebarSession::query()->where('public_id', $generation)->find($id);
        $permit = $session?->memory_permit;
        $authority = config('agent-sidebar.memory.authorizer');
        if ($permit === null || $authority === null || ! config('agent-sidebar.memory.enabled')) {
            return;
        }

        $model = new ModelSelection($permit['provider'], $permit['model']);
        $invoked = false;
        app($authority)->run($permit['grant'], $model, function (AuthorizedRun $run) use ($session, $model, &$invoked): void {
            $invoked = true;
            if ($run->subject->actorKey === $session->actor_key && $run->subject->scopeKey === $session->scope_key) {
                $this->refresh($session, $run, $model);
            }
        });

        if (! $invoked) {
            $this->store->mutate($session, fn (SidebarSession $fresh) => $fresh->update(['memory_permit' => null]), compareVersion: true);
        }
    }

    private function refresh(SidebarSession $session, AuthorizedRun $run, ModelSelection $model): void
    {
        ($run->authorize)();
        $context = new TurnContext($run->subject, $this->sources, $run->authorize);
        [, $recentStart] = $this->history->recent($session, $context, $model, 0);
        $keyList = config('agent-sidebar.memory.sections', []);
        $short = $session->short_rebuild ? null : $this->store->get($run->subject, 'short', 'summary');
        $shortThrough = $short === null ? 0 : (int) $session->short_through;
        [$older, $olderDependencies, $until] = $this->batch($session, $run, $model, $shortThrough, ($recentStart ?? ($session->memory_pending + 1)) - 1);
        [$new, $newDependencies, $processed] = $this->batch($session, $run, $model, (int) $session->memory_processed, (int) $session->memory_pending);
        $wiki = [];
        $wikiDependencies = [];
        foreach ($keyList as $key) {
            if ($entry = $this->store->get($run->subject, 'wiki', $key)) {
                $wiki[$key] = $this->store->wikiText($entry);
                $wikiDependencies += $entry->dependencies;
            }
        }

        $shortDependencies = $olderDependencies + ($short?->dependencies ?? []);
        $dependencies = $shortDependencies + $newDependencies + $wikiDependencies;
        $assertCurrent = function () use ($run, $session, $dependencies): void {
            ($run->authorize)();
            if ($this->sources->current($run->subject, $dependencies) === null
                || ! SidebarSession::query()->where('public_id', $session->public_id)->whereKey($session->id)->exists()) {
                throw new TurnStopped('memory_changed');
            }
        };
        $staged = [];

        try {
            $answer = [];
            if ($older !== [] || ($new !== [] && $keyList !== [])) {
                $assertCurrent();
                $answer = $this->summarize($model, $older, $short?->content, $new, $wiki, $keyList);
                foreach ($answer['sections'] as $key => $section) {
                    $staged[$key] = $this->files->stage($run->subject, $section['text']);
                }
            }

            $committed = $this->store->mutate($session, function (SidebarSession $fresh) use (
                $run, $model, $answer, $older, $until, $processed, $staged, $dependencies, $assertCurrent
            ): void {
                $assertCurrent();
                $canonical = $this->store->flatten($run->subject, $dependencies);
                if ($older !== []) {
                    if ($answer['short_summary'] === '') {
                        throw new TurnStopped('empty_short_summary');
                    }
                    $summary = $this->budget->fit($answer['short_summary'], (int) config('agent-sidebar.memory.short_tokens', 700), $model);
                    $this->store->save($fresh, 'short', 'summary', $summary, $canonical);
                } elseif ($fresh->short_rebuild) {
                    MemoryEntry::query()->where('session_id', $fresh->id)->where('kind', 'short')->delete();
                }
                foreach ($staged as $key => $file) {
                    $this->store->save($fresh, 'wiki', $key, $answer['sections'][$key]['summary'], $canonical, $file, preserveRevision: true);
                }
                $fresh->update(['short_through' => $until, 'memory_processed' => $processed, 'short_rebuild' => false]);
            }, compareVersion: true);
            if ($committed) {
                $staged = [];
            }
        } finally {
            foreach ($staged as $file) {
                $this->files->retire($file);
            }
        }

        $fresh = SidebarSession::query()->where('public_id', $session->public_id)->find($session->id);
        if ($fresh !== null && (! $committed || $fresh->memory_pending > $processed
            || ($recentStart !== null && $fresh->turns()->where('status', 'completed')->where('id', '>', $until)->where('id', '<', $recentStart)->exists()))) {
            $this->scheduler->dispatch($fresh);
        }
    }

    /** @return array{list<array>, array<string, string>, int} */
    private function batch(SidebarSession $session, AuthorizedRun $run, ModelSelection $model, int $after, int $through): array
    {
        $rows = $session->turns()->where('status', 'completed')->where('id', '>', $after)->where('id', '<=', $through)
            ->orderBy('id')->limit(12)->get();
        $turns = [];
        $dependencies = [];
        foreach ($rows as $row) {
            $after = (int) $row->id;
            $resolved = $row->dependencies ?? [];
            if ($this->sources->current($run->subject, $resolved) === null) {
                continue;
            }

            $dependencies += $resolved;
            $turns[] = ['question' => $this->budget->fit($row->question, 400, $model), 'answer' => $this->budget->fit($row->answer, 600, $model)];
        }

        return [$turns, $dependencies, $after];
    }

    private function summarize(ModelSelection $model, array $older, ?string $summary, array $new, array $wiki, array $keys): array
    {
        $output = trim((new MemoryAgent)->prompt(json_encode([
            'older_turns' => $older, 'short_summary' => $summary, 'new_turns' => $new,
            'sections' => $wiki, 'allowed_section_keys' => $keys,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), provider: $model->provider, model: $model->model, timeout: 25)->text);
        $output = preg_replace('/\A\x60{3}(?:json)?\s*(.*?)\s*\x60{3}\z/is', '$1', $output) ?? '';

        $decoded = strlen($output) <= 32768 ? json_decode($output, true) : null;

        return Validator::make(is_array($decoded) ? $decoded : [], [
            'short_summary' => ['present', 'string', 'max:4000'],
            'sections' => ['present', $keys === [] ? 'size:0' : 'array:'.implode(',', $keys), 'array'],
            'sections.*' => ['required', 'array:text,summary'],
            'sections.*.text' => ['required', 'string', 'max:12000'],
            'sections.*.summary' => ['required', 'string', 'max:1500'],
        ])->validate();
    }
}
