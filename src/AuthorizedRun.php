<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Closure;

/** Host-supplied authority, created only after authenticating the current principal. */
final readonly class AuthorizedRun
{
    /**
     * @param  Closure(): void  $authorize
     * @param  (Closure(int): void)|null  $beforeStep
     * @param  (Closure(string): bool)|null  $allowsTool
     */
    public function __construct(
        public Subject $subject,
        public Closure $authorize,
        public ?Closure $beforeStep = null,
        public ?Closure $allowsTool = null,
        public ?ModelSelection $model = null,
    ) {}
}
