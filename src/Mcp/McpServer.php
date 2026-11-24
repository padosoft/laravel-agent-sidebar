<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Mcp;

use Laravel\Mcp\Client\Schema\ToolResult as RemoteResult;
use Laravel\Mcp\WebClient;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\ToolResult;

interface McpServer
{
    public function name(): string;

    /** @return list<McpToolDefinition> Server-configured, read-only tools. */
    public function tools(): array;

    public function allows(Subject $subject, string $tool, string $channel): bool;

    public function client(Subject $subject): WebClient;

    /** Extract only authorized data. Include revalidatable source IDs for private results. */
    public function mapResult(Subject $subject, string $tool, RemoteResult $result): ToolResult;
}
