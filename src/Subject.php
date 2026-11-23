<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class Subject
{
    public string $actorKey;

    public string $scopeKey;

    public function __construct(public Authenticatable $actor, public string $scope)
    {
        $this->actorKey = self::keyFor($actor);
        $this->scopeKey = hash('sha256', $scope);
    }

    public static function keyFor(Authenticatable $actor): string
    {
        return hash('sha256', $actor::class.':'.(string) $actor->getAuthIdentifier());
    }
}
