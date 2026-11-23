<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\ToolResult;

interface ReadTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array;

    /** @return array<string, mixed> */
    public function rules(): array;

    /**
     * Apply the project's read policies and return only the needed data.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function read(Subject $subject, array $arguments): ToolResult;
}
