<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class SidebarController
{
    public function __construct(
        private PageContext $pages,
        private Conversations $conversations,
        private SourceAccess $sources,
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
        $receipt = app(SubmitTurn::class)->handle($request, $request->all());

        return response()->json($receipt['data'], $receipt['status']);
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
