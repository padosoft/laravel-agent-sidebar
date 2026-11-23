<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Padosoft\AgentSidebar\Contracts\Host;

final readonly class PageContext
{
    public function __construct(private Host $host) {}

    public function subject(Request $request): ?Subject
    {
        $actor = $request->user();

        if ($actor === null || $actor->getAuthIdentifier() === null) {
            return null;
        }

        $scope = $this->host->scope($request, $actor);

        return $scope === null || $scope === '' ? null : new Subject($actor, $scope);
    }

    /** @return array{token: string, label: string}|null */
    public function share(Request $request): ?array
    {
        $route = $request->route();
        $subject = $this->subject($request);

        if ($subject === null || ! $request->isMethod('GET') || ! $request->hasSession() || $route?->getName() === null) {
            return null;
        }

        $parameters = [];
        foreach ($route->parameters() as $key => $value) {
            $value = $value instanceof UrlRoutable ? $value->getRouteKey() : $value;
            if (is_string($value) || is_int($value)) {
                $parameters[$key] = (string) $value;
            }
        }

        $page = $this->host->page($subject, $route->getName(), $parameters);
        if ($page === null) {
            return null;
        }

        return [
            'token' => Crypt::encryptString(json_encode([
                'purpose' => 'agent-sidebar:page:v1',
                'actor' => $subject->actorKey,
                'scope' => $subject->scopeKey,
                'session' => $this->sessionFingerprint($request),
                'expires' => now()->addSeconds((int) config('agent-sidebar.context_ttl'))->timestamp,
                'route' => $route->getName(),
                'parameters' => $parameters,
            ], JSON_THROW_ON_ERROR)),
            'label' => $page->label,
        ];
    }

    /** @return array{route: string, parameters: array<string, string>} */
    public function resolve(Request $request, Subject $subject, string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $data = null;
        }

        if (! is_array($data) || ($data['purpose'] ?? null) !== 'agent-sidebar:page:v1'
            || ($data['actor'] ?? null) !== $subject->actorKey
            || ($data['scope'] ?? null) !== $subject->scopeKey
            || ($data['session'] ?? null) !== $this->sessionFingerprint($request)
            || ($data['expires'] ?? 0) <= now()->timestamp) {
            throw ValidationException::withMessages(['context_token' => 'Refresh the page to renew its assistant context.']);
        }

        return ['route' => $data['route'], 'parameters' => $data['parameters']];
    }

    private function sessionFingerprint(Request $request): ?string
    {
        return $request->hasSession() ? hash('sha256', $request->session()->getId()) : null;
    }
}
