<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Mcp;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final readonly class McpToolDefinition
{
    /**
     * @param  Closure(JsonSchema): array  $schema
     * @param  array<string, mixed>  $rules
     */
    public function __construct(public string $name, public string $description, public Closure $schema, public array $rules, public bool $publicResult = false) {}
}
