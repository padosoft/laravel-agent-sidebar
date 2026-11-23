<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Padosoft\AgentSidebar\Page;
use Padosoft\AgentSidebar\Source;
use Padosoft\AgentSidebar\Subject;

interface Host
{
    /**
     * Resolve the authorized space from the current request. Null denies sidebar access.
     * This is also called during generation, so consult current memberships and permissions.
     */
    public function scope(Request $request, Authenticatable $actor): ?string;

    /**
     * Resolve the named page and authorize its resources before returning context.
     * All non-public data in the summary must be covered by sourceIds.
     *
     * @param  array<string, string>  $parameters
     */
    public function page(Subject $subject, string $route, array $parameters): ?Page;

    /**
     * Return only currently visible sources within the subject's space.
     * Include a revision when changes to a resource's visibility or content invalidate stored answers.
     *
     * @param  list<string>  $ids
     * @return iterable<Source>
     */
    public function sources(Subject $subject, array $ids): iterable;
}
