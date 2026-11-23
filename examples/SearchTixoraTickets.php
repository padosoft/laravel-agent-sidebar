<?php

declare(strict_types=1);

namespace App\Domains\Knowledge\Services;

use App\Domains\Tenancy\Support\TenantContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Padosoft\AgentSidebar\Contracts\ReadTool;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\ToolResult;

final readonly class SearchTixoraTickets implements ReadTool
{
    public function __construct(private KnowledgeAgentSources $knowledge, private TenantContext $tenants) {}

    public function name(): string
    {
        return 'search_tickets';
    }

    public function description(): string
    {
        return 'Search visible tickets by public ID or by text in the subject.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required()];
    }

    public function rules(): array
    {
        return ['query' => ['required', 'string', 'max:120']];
    }

    public function read(Subject $subject, array $arguments): ToolResult
    {
        $tenant = $this->tenants->current();
        abort_unless($subject->actor instanceof User && $tenant !== null && $subject->scope === 'tenant:'.$tenant->id, 403);

        $tickets = $this->knowledge->searchTickets($subject->actor, $arguments['query']);
        $rows = array_map(
            fn (array $ticket): array => array_intersect_key($ticket, array_flip(['id', 'title', 'status', 'priority', 'created_at'])),
            $tickets,
        );

        return new ToolResult(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), array_column($rows, 'id'));
    }
}
