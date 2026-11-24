<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Padosoft\AgentSidebar\Mcp\McpServer;

final class SidebarConfiguration
{
    private array $options = [];

    private array $tools = [];

    private array $servers = [];

    public function usingModel(?string $provider, ?string $model): self
    {
        $this->options['model_selection'] = new ModelSelection($provider, $model);

        return $this;
    }

    /** @param class-string<Contracts\ModelResolver> $resolver */
    public function resolveModelUsing(string $resolver): self
    {
        $this->options['model_resolver'] = $resolver;

        return $this;
    }

    /** @param class-string<Contracts\ReadTool> ...$tools */
    public function withTools(string ...$tools): self
    {
        array_push($this->tools, ...$tools);

        return $this;
    }

    public function withMemory(MemoryOptions $memory): self
    {
        $this->options['memory'] = array_replace(config('agent-sidebar.memory', []), $memory->options());

        return $this;
    }

    /** @param class-string<McpServer> $server */
    public function withMcpServer(string $server): self
    {
        $this->servers[] = $server;

        return $this;
    }

    public function register(): void
    {
        $tools = [...config('agent-sidebar.tools', []), ...$this->tools];
        $servers = [...config('agent-sidebar.mcp_servers', []), ...$this->servers];
        app(ToolRegistry::class)->validate($tools, $servers);

        foreach ([...$this->options, 'tools' => $tools, 'mcp_servers' => $servers] as $key => $value) {
            config(['agent-sidebar.'.$key => $value]);
        }
    }
}
