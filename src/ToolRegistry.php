<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\WebClient;
use Padosoft\AgentSidebar\Contracts\Host;
use Padosoft\AgentSidebar\Contracts\ReadTool;
use Padosoft\AgentSidebar\Contracts\ToolAuthorization;
use Padosoft\AgentSidebar\Mcp\McpReadTool;
use Padosoft\AgentSidebar\Mcp\McpServer;
use Padosoft\AgentSidebar\Memory\ReadWikiSection;

final readonly class ToolRegistry
{
    public function __construct(private Host $host) {}

    public function validate(array $classes, array $servers): void
    {
        $this->definitions($classes, $servers, 'web');
    }

    /** @return list<SdkTool> */
    public function tools(TurnContext $context, AuthorizedRun $run, string $channel): array
    {
        $tools = [];
        foreach ($this->definitions(config('agent-sidebar.tools', []), config('agent-sidebar.mcp_servers', []), $channel, $run->subject) as $tool) {
            $allowed = fn (): bool => ($run->allowsTool === null || ($run->allowsTool)($tool->name()))
                && (! $this->host instanceof ToolAuthorization || $this->host->allowsTool($run->subject, $tool->name(), $channel));
            if ($allowed()) {
                $tools[] = new SdkTool($tool, $context, $allowed);
            }
        }

        return $tools;
    }

    /** @return array<string, ReadTool> */
    private function definitions(array $classes, array $servers, string $channel, ?Subject $subject = null): array
    {
        $tools = [];
        foreach ($classes as $class) {
            if (! is_a($class, ReadTool::class, true)) {
                throw new InvalidArgumentException('Invalid sidebar read tool.');
            }

            $this->add($tools, app($class));
        }

        if ($channel === 'web' && config('agent-sidebar.memory.enabled') && config('agent-sidebar.memory.sections', []) !== []) {
            $this->add($tools, app(ReadWikiSection::class));
        }

        foreach ($servers as $class) {
            if (! class_exists(WebClient::class) || ! is_a($class, McpServer::class, true)) {
                throw new InvalidArgumentException('A sidebar MCP server requires laravel/mcp and a valid server adapter.');
            }

            $server = app($class);
            foreach ($server->tools() as $definition) {
                $tool = new McpReadTool($server, $definition, $channel);
                $this->add($tools, $tool);
            }
        }

        return $subject === null ? $tools : array_filter($tools, fn (ReadTool $tool): bool => ! $tool instanceof McpReadTool || $tool->allows($subject));
    }

    private function add(array &$tools, ReadTool $tool): void
    {
        $name = $tool->name();
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,63}\z/', $name) || isset($tools[$name])
            || ($name === 'read_wiki_section' && ! $tool instanceof ReadWikiSection)
        ) {
            throw new InvalidArgumentException('Invalid or duplicate sidebar tool: '.$name);
        }

        foreach ($tool->schema(new JsonSchemaTypeFactory) as $field => $type) {
            if (! is_string($field) || ! $type instanceof Type || ! array_key_exists($field, $tool->rules())) {
                throw new InvalidArgumentException('Invalid schema or missing validation rule for sidebar tool: '.$name);
            }
        }

        $tools[$name] = $tool;
    }
}
