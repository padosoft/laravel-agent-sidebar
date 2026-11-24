<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

final readonly class ModelSelection
{
    public function __construct(public ?string $provider, public ?string $model) {}

    public static function resolve(Subject $subject, ?self $override = null): self
    {
        if ($override !== null) {
            return $override;
        }

        $resolver = config('agent-sidebar.model_resolver');

        return ($resolver ? app($resolver)->resolve($subject) : null)
            ?? config('agent-sidebar.model_selection')
            ?? new self(config('agent-sidebar.provider'), config('agent-sidebar.model'));
    }
}
