<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Http\Request;

final class TurnBuilder
{
    private ?ModelSelection $model = null;

    /** @var array<string, ContextValue> */
    private array $context = [];

    public function __construct(private readonly Request $request) {}

    public function usingModel(?string $provider, ?string $model): self
    {
        $this->model = new ModelSelection($provider, $model);

        return $this;
    }

    public function withContext(string $key, ContextValue $value): self
    {
        ContextValue::assertKey($key);
        $this->context[$key] = $value;

        return $this;
    }

    /** @return array{status: int, data: array} */
    public function submit(string $message, string $clientMessageId, string $contextToken, ?string $sessionId = null): array
    {
        return app(SubmitTurn::class)->handle($this->request, [
            'message' => $message, 'client_message_id' => $clientMessageId, 'context_token' => $contextToken, 'session_id' => $sessionId,
        ], $this->model, $this->context);
    }
}
