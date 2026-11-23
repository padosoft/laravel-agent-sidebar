<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Padosoft\AgentSidebar\Contracts\Host;
use Padosoft\AgentSidebar\Contracts\ReadTool;
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
    ) {}

    /** @param array{route: string, parameters: array<string, string>} $reference */
    public function run(Request $request, Subject $subject, SidebarSession $session, SidebarTurn $turn, array $reference, Page $page): void
    {
        $context = new TurnContext($subject, $this->sources, function () use ($request, $subject, $turn, $reference): void {
            if ($turn->expires_at->lessThanOrEqualTo(now())) {
                throw new TurnStopped('turn_expired');
            }

            $current = $this->pages->subject($request);
            if ($current?->actorKey !== $subject->actorKey || $current->scopeKey !== $subject->scopeKey
                || $this->host->page($current, $reference['route'], $reference['parameters']) === null
                || ! SidebarTurn::query()->whereKey($turn->getKey())->where('active_slot', 1)->exists()) {
                throw new TurnStopped('access_changed');
            }
        });

        try {
            $context->collect($page->sourceIds);
            $agent = new SidebarAgent($context, $context->history($session), $this->tools($context));
            $context->check();

            $prompt = json_encode([
                'question' => $turn->question,
                'page' => [
                    'label' => mb_substr($page->label, 0, 150),
                    'context' => mb_substr($page->summary, 0, 6000),
                    'source_ids' => $page->sourceIds,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $response = $agent->prompt(
                $prompt,
                provider: config('agent-sidebar.provider'),
                model: config('agent-sidebar.model'),
                timeout: min(25, max(1, (int) config('agent-sidebar.turn_ttl'))),
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
            ]);
            if ($answer->fails()) {
                throw new TurnStopped('invalid_response');
            }

            $context->check();
            $validated = $answer->validated();
            $this->conversations->complete($turn, $validated['answer'], $context->dependencies, $validated['source_ids']);
        } catch (TurnStopped $exception) {
            $this->conversations->fail($turn, $exception->getMessage());
        } catch (NoSuchToolException) {
            $this->conversations->fail($turn, 'unknown_tool');
        } catch (Throwable $exception) {
            Log::warning('Agent sidebar provider or tool failed.', [
                'turn' => $turn->client_message_id,
                'type' => $exception::class,
                'location' => basename($exception->getFile()).':'.$exception->getLine(),
            ]);

            $this->conversations->fail($turn, 'generation_failed');
        }
    }

    /** @return list<SdkTool> */
    private function tools(TurnContext $context): array
    {
        $tools = [];
        foreach (config('agent-sidebar.tools', []) as $class) {
            $tool = app($class);
            if (! $tool instanceof ReadTool || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,63}\z/', $tool->name()) || isset($tools[$tool->name()])) {
                throw new TurnStopped('invalid_tool_registry');
            }

            $tools[$tool->name()] = new SdkTool($tool, $context);
        }

        return array_values($tools);
    }
}
