<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Padosoft\AgentSidebar\Contracts\Host;

final readonly class SidebarController
{
    public function __construct(
        private Host $host,
        private PageContext $pages,
        private Conversations $conversations,
        private SourceAccess $sources,
        private TurnRunner $runner,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $subject = $this->subject($request);
        $session = $this->conversations->current($subject);
        if ($session === null) {
            return response()->json(['session_id' => null, 'turns' => [], 'next_cursor' => null]);
        }

        $this->conversations->expire($session);
        $input = $request->validate(['before' => ['sometimes', 'integer', 'min:1']]);
        $turns = $session->turns()->latest('id')
            ->when(isset($input['before']), fn ($query) => $query->where('id', '<', $input['before']))
            ->limit(21)->get();

        return response()->json([
            'session_id' => $session->public_id,
            'turns' => $turns->take(20)->reverse()->values()->map(fn ($turn): array => $this->sources->turn($subject, $turn)),
            'next_cursor' => $turns->count() > 20 ? $turns[19]->getKey() : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $subject = $this->subject($request);
        $rateKey = 'agent-sidebar:'.$subject->actorKey.':'.$subject->scopeKey;
        $input = $request->validate([
            'session_id' => ['present', 'nullable', 'uuid'],
            'client_message_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:3000'],
            'context_token' => ['required', 'string', 'max:16384'],
        ]);

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
            $this->runner->run($request, $subject, $session, $turn, $reference, $page);
        }

        $currentSubject = $this->subject($request);
        abort_unless($subject->scopeKey === $currentSubject->scopeKey && $subject->actorKey === $currentSubject->actorKey, 403);
        $this->conversations->expire($session);
        $turn->refresh();

        return response()->json(
            ['session_id' => $session->public_id, 'turn' => $this->sources->turn($subject, $turn)],
            $turn->status === 'processing' ? 202 : ($created ? 201 : 200),
        );
    }

    public function turn(Request $request): JsonResponse
    {
        $subject = $this->subject($request);
        $session = $this->conversations->current($subject);
        abort_if($session === null, 404);

        $this->conversations->expire($session);
        $turn = $session->turns()->where('client_message_id', strtolower($request->route('clientMessageId')))->firstOrFail();

        return response()->json(['session_id' => $session->public_id, 'turn' => $this->sources->turn($subject, $turn)]);
    }

    public function destroy(Request $request): Response
    {
        $this->conversations->reset($this->subject($request), strtolower($request->route('sessionId')));

        return response()->noContent();
    }

    private function subject(Request $request): Subject
    {
        return $this->pages->subject($request) ?? abort(403);
    }
}
