<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

final readonly class ToolResult
{
    /**
     * List every source that contributed non-public data, including rows summarized in aggregate.
     *
     * @param  list<string>  $sourceIds
     */
    public function __construct(
        public string $text,
        public array $sourceIds = [],
    ) {}
}
