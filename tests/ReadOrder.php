<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Tests;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Padosoft\AgentSidebar\Contracts\ReadTool;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\ToolResult;

final class ReadOrder implements ReadTool
{
    public int $calls = 0;

    public function name(): string
    {
        return 'read_order';
    }

    public function description(): string
    {
        return 'Read the current status of a synthetic order by its integer ID.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->integer()->required()];
    }

    public function rules(): array
    {
        return ['order_id' => ['required', 'integer', 'in:1']];
    }

    public function read(Subject $subject, array $arguments): ToolResult
    {
        $this->calls++;
        abort_unless($subject->scope === 'workspace:acme', 403);

        return new ToolResult('Order 1 status: turquoise-marigold.', ['order:1']);
    }
}
