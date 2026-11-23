<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

final readonly class Page
{
    /** @param list<string> $sourceIds */
    public function __construct(
        public string $label,
        public string $summary = '',
        public array $sourceIds = [],
    ) {}
}
