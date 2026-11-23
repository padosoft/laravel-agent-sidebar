<?php

declare(strict_types=1);

namespace App\Domains\Knowledge\Services;

use App\Domains\Tenancy\Services\PanelFeatures;
use App\Domains\Tenancy\Support\TenantContext;
use App\Domains\Ticketing\Services\TicketAccess;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Padosoft\AgentSidebar\Contracts\Host;
use Padosoft\AgentSidebar\Page;
use Padosoft\AgentSidebar\Source;
use Padosoft\AgentSidebar\Subject;

final readonly class TixoraHost implements Host
{
    public function __construct(
        private TenantContext $tenantContext,
        private TicketAccess $access,
        private PanelFeatures $features,
        private KnowledgeAgentSources $knowledge,
    ) {}

    public function scope(Request $request, Authenticatable $actor): ?string
    {
        $tenant = $this->tenantContext->current();

        if (! $actor instanceof User || $tenant === null
            || ! $this->access->staff($actor, $tenant->id)
            || ! ($this->features->forTenant($tenant)['agent-chat']['available'] ?? false)) {
            return null;
        }

        return 'tenant:'.$tenant->id;
    }

    public function page(Subject $subject, string $route, array $parameters): ?Page
    {
        $tenant = $this->tenantContext->current();
        $label = config('agent-sidebar.pages', [])[$route] ?? null;

        if ($tenant === null || $subject->scope !== 'tenant:'.$tenant->id || ! is_string($label)
            || ! $this->features->forRoute($route)?->availableTo($tenant)) {
            return null;
        }

        return new Page($label);
    }

    public function sources(Subject $subject, array $ids): iterable
    {
        $actor = $subject->actor;
        $tenant = $this->tenantContext->current();
        if (! $actor instanceof User || $tenant === null || $subject->scope !== 'tenant:'.$tenant->id) {
            return;
        }

        $prefix = KnowledgeAgentSources::TICKET_SUMMARY_PREFIX;
        $tickets = [];
        foreach ($ids as $id) {
            if (str_starts_with($id, $prefix)) {
                $tickets[] = substr($id, strlen($prefix));
            }
        }

        foreach ($this->knowledge->currentTicketSummaries($actor, $tickets) as $source) {
            yield new Source($source['id'], $source['title'], $source['url'], $source['hash']);
        }
    }
}
