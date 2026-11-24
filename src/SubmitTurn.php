<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Padosoft\AgentSidebar\Contracts\Host;

final readonly class SubmitTurn
{
    public function __construct(
        private Host $host,
        private PageContext $pages,
        private Conversations $conversations,
        private SourceAccess $sources,
        private TurnRunner $runner,
    ) {}

    /** @param array<string, ContextValue> $context */
    public function handle(Request $request, array $input, ?ModelSelection $model = null, array $context = []): array
    {
        $subject = $this->pages->subject($request) ?? abort(403);
        $rateKey = 'agent-sidebar:'.$subject->actorKey.':'.$subject->scopeKey;
        $input = Validator::make($input, [
            'session_id' => ['present', 'nullable', 'uuid'],
            'client_message_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:3000'],
            'context_token' => ['required', 'string', 'max:16384'],
        ])->validate();

        $reference = $this->pages->resolve($request, $subject, $input['context_token']);
        $page = $this->host->page($subject, $reference['route'], $reference['parameters']);
        abort_if($page === null, 403);

        [$session, $turn, $created] = $this->conversations->reserve(
            $subject,
            $input['session_id'] === null ? null : strtolower($input['session_id']),
            strtolower($input['client_message_id']),
            $input['message'],
            function () use ($rateKey): void {
                if (RateLimiter::tooManyAttempts($rateKey, (int) config('agent-sidebar.requests_per_minute'))) {
                    abort(response()->json(['code' => 'rate_limited'], 429, ['Retry-After' => RateLimiter::availableIn($rateKey)]));
                }
            },
        );

        if ($created) {
            RateLimiter::hit($rateKey);
            $this->runner->run($request, $subject, $session, $turn, $reference, $page, $model, $context);
        }

        $currentSubject = $this->pages->subject($request) ?? abort(403);
        abort_unless($subject->scopeKey === $currentSubject->scopeKey && $subject->actorKey === $currentSubject->actorKey, 403);
        $this->conversations->expire($session);
        $turn->refresh();

        return [
            'data' => ['session_id' => $session->public_id, 'turn' => $this->sources->turn($subject, $turn)],
            'status' => $turn->status === 'processing' ? 202 : ($created ? 201 : 200),
        ];
    }
}
