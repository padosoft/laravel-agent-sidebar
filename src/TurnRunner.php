<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Padosoft\AgentSidebar\Contracts\Host;
use Padosoft\AgentSidebar\Memory\MemoryPrompt;
use Padosoft\AgentSidebar\Memory\MemoryScheduler;
use Padosoft\AgentSidebar\Models\SidebarSession;
use Padosoft\AgentSidebar\Models\SidebarTurn;
use Throwable;

final readonly class TurnRunner
{
    public function __construct(
        private Host $host,
        private PageContext $pages,
        private SourceAccess $sources,
        private Conversations $conversations,
        private ToolRegistry $registry,
        private MemoryPrompt $memory,
        private MemoryScheduler $scheduler,
    ) {}

    /** @param array<string, ContextValue> $extraContext */
    public function run(Request $request, Subject $subject, SidebarSession $session, SidebarTurn $turn, array $reference, Page $page, ?ModelSelection $override = null, array $extraContext = []): void
    {
        $run = new AuthorizedRun($subject, function () use ($request, $subject, $turn, $reference): void {
            if ($turn->expires_at->lessThanOrEqualTo(now())) {
                throw new TurnStopped('turn_expired');
            }

            $current = $this->pages->subject($request);
            if ($current?->actorKey !== $subject->actorKey || $current->scopeKey !== $subject->scopeKey
                || $this->host->page($current, $reference['route'], $reference['parameters']) === null
                || ! SidebarTurn::query()->whereKey($turn->getKey())->where('active_slot', 1)->exists()) {
                throw new TurnStopped('access_changed');
            }
        }, model: $override);
        $context = new TurnContext($subject, $this->sources, $run->authorize);

        try {
            $model = ModelSelection::resolve($subject, $override);
            $context->collect($page->sourceIds);
            [$history, $extra] = $this->memory->prepare($session, $context, $model);
            $extra['turn_context'] = $this->extra($context, $extraContext);
            $answer = $this->answer($run, $context, $model, $turn->question, $page, $history, $extra, 'web');

            if ($this->conversations->complete($turn, $answer['text'], $context->dependencies, $answer['source_ids'])) {
                $this->scheduler->afterTurn($request, $subject, $session, (int) $turn->id, $model);
            }
        } catch (TurnStopped $exception) {
            $this->conversations->fail($turn, $exception->getMessage());
        } catch (NoSuchToolException) {
            $this->conversations->fail($turn, 'unknown_tool');
        } catch (Throwable $exception) {
            Log::warning('Agent sidebar provider, tool or memory dispatch failed.', [
                'turn' => $turn->client_message_id,
                'type' => $exception::class,
                'location' => basename($exception->getFile()).':'.$exception->getLine(),
            ]);
            $this->conversations->fail($turn, 'generation_failed');
        }
    }

    /**
     * Headless execution. It intentionally does not query the session or schedule memory.
     *
     * @param  array<string, ContextValue>  $context
     * @return array{text: string, sources: list<array>, missing_information: list<string>}
     */
    public function runTask(AuthorizedRun $run, string $message, array $context = []): array
    {
        Validator::make(['message' => $message], ['message' => ['required', 'string', 'max:10000']])->validate();

        ($run->authorize)();
        $turn = new TurnContext($run->subject, $this->sources, $run->authorize, allowsWiki: false);
        $result = $this->answer(
            $run, $turn, ModelSelection::resolve($run->subject, $run->model), $message, new Page('Task'), [],
            ['task_context' => $this->extra($turn, $context)], 'pact',
        );
        unset($result['source_ids']);

        return $result;
    }

    private function extra(TurnContext $context, array $values): array
    {
        $extra = [];
        $size = 0;
        foreach ($values as $key => $value) {
            ContextValue::assertKey($key);
            $size += strlen($value->text);
            if ($size > 6000) {
                throw new TurnStopped('context_limit');
            }
            $context->collect($value->sourceIds, citeable: false);
            $extra[$key] = $value->text;
        }

        return $extra;
    }

    private function answer(AuthorizedRun $run, TurnContext $context, ModelSelection $model, string $message, Page $page, array $history, array $extra, string $channel): array
    {
        $agent = new SidebarAgent($context, $history, $this->registry->tools($context, $run, $channel), $run->beforeStep, $channel);
        $context->check();
        $prompt = json_encode([
            'question' => $message,
            'page' => ['label' => mb_substr($page->label, 0, 150), 'context' => mb_substr($page->summary, 0, 6000), 'source_ids' => $page->sourceIds],
            ...$extra,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response = $agent->prompt(
            $prompt, provider: $model->provider, model: $model->model,
            timeout: max(1, min((int) config('agent-sidebar.inference_timeout', 25), (int) config('agent-sidebar.turn_ttl'))),
        );

        $text = trim($response->text);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $text, $fenced)) {
            $text = $fenced[1];
        }
        $data = strlen($text) <= 32768 ? json_decode($text, true) : null;
        $answer = Validator::make(is_array($data) ? $data : [], [
            'answer' => ['required', 'string', 'max:6000'],
            'source_ids' => ['present', 'array', 'list', 'max:20'],
            'source_ids.*' => ['required', 'string', 'distinct', Rule::in(array_keys($context->citations))],
            'missing_information' => ['sometimes', 'array', 'list', 'max:10'],
            'missing_information.*' => ['required', 'string', 'max:500'],
        ]);
        if ($answer->fails()) {
            throw new TurnStopped('invalid_response');
        }
        $context->check();
        $validated = $answer->validated();

        return [
            'text' => $validated['answer'],
            'source_ids' => $validated['source_ids'],
            'sources' => array_map(fn (string $id): array => $context->citations[$id]->citation(), $validated['source_ids']),
            'missing_information' => $validated['missing_information'] ?? [],
        ];
    }
}
