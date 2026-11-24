<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Padosoft\AgentSidebar\Contracts\ReadTool;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\ToolResult;
use Padosoft\AgentSidebar\TurnStopped;

final readonly class McpReadTool implements ReadTool
{
    public function __construct(private McpServer $server, private McpToolDefinition $definition, private string $channel) {}

    public function name(): string
    {
        return 'mcp_'.$this->server->name().'_'.$this->definition->name;
    }

    public function description(): string
    {
        return $this->definition->description;
    }

    public function schema(JsonSchema $schema): array
    {
        return ($this->definition->schema)($schema);
    }

    public function rules(): array
    {
        return $this->definition->rules;
    }

    public function allows(Subject $subject): bool
    {
        return $this->server->allows($subject, $this->definition->name, $this->channel);
    }

    public function read(Subject $subject, array $arguments): ToolResult
    {
        $name = $this->definition->name;
        if (! $this->server->allows($subject, $name, $this->channel)) {
            throw new TurnStopped('access_changed');
        }

        $result = $this->server->client($subject)->withTimeout(8)->callTool($name, $arguments);
        if ($result->isError || ! $this->server->allows($subject, $name, $this->channel)) {
            throw new TurnStopped('mcp_read_failed');
        }

        $mapped = $this->server->mapResult($subject, $name, $result);
        if (! $this->definition->publicResult && $mapped->sourceIds === []) {
            throw new TurnStopped('mcp_source_required');
        }

        return $mapped;
    }
}
