<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Pact;

use Illuminate\Validation\ValidationException;
use PactLaravel\Contracts\AgentHandler;
use PactLaravel\Execution\AgentContext;
use PactLaravel\Execution\AgentResult;
use Padosoft\AgentSidebar\AuthorizedRun;
use Padosoft\AgentSidebar\ContextValue;
use Padosoft\AgentSidebar\TurnRunner;
use Padosoft\AgentSidebar\TurnStopped;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class PactAgentHandler implements AgentHandler
{
    public const CONTRACT = 'pact.agent.chat.v1';

    public function __construct(private TurnRunner $runner) {}

    public function handle(AgentContext $context): AgentResult
    {
        $authority = config('agent-sidebar.pact.authorizer');
        if (! config('agent-sidebar.pact.enabled') || $authority === null) {
            return AgentResult::error(['code' => 'AUTHORIZATION', 'message' => 'Agent chat is unavailable.', 'retryable' => false]);
        }

        $questionKey = (string) config('agent-sidebar.pact.clarification_key', 'agent-clarification');
        $message = $context->input['message'] ?? null;
        $clarification = $context->answers[$questionKey]['text'] ?? null;
        if (! is_string($message) || trim($message) === '' || mb_strlen($message) > 10000
            || ($context->answers !== [] && (! is_string($clarification) || trim($clarification) === '' || mb_strlen($clarification) > 2000))) {
            return AgentResult::error(['code' => 'INVALID_ANSWER', 'message' => 'Supply a message and any requested clarification as text.', 'retryable' => false]);
        }

        try {
            return app($authority)->execute($context, function (AuthorizedRun $run) use ($context, $message, $clarification, $questionKey): AgentResult {
                $answer = $this->runner->runTask($run, $message, $clarification === null ? [] : ['clarification' => new ContextValue($clarification)]);
                if ($clarification === null && $answer['missing_information'] !== []) {
                    return AgentResult::question([[
                        'question_id' => $questionKey,
                        'task_id' => $context->taskId,
                        'prompt' => mb_strcut(implode("\n", $answer['missing_information']), 0, 3000),
                        'answer_schema' => [
                            'schema_uri' => 'https://github.com/bramato/Pact/blob/main/sdk/laravel/resources/contracts/answer.json',
                            'sha256' => '35599801c0eaecab86245e2744955d0727ad250fb62b03bb4b4da087512efb44',
                        ],
                        'required' => true,
                        'expires_at' => now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'),
                        'status' => 'open',
                    ]]);
                }

                return AgentResult::completed(['text' => $answer['text'], 'sources' => $answer['sources']]);
            });
        } catch (TurnStopped|ValidationException|HttpExceptionInterface) {
            return AgentResult::error(['code' => 'AUTHORIZATION', 'message' => 'The agent could not complete this task under its current permissions or limits.', 'retryable' => false]);
        }
    }
}
