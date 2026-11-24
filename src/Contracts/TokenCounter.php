<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Contracts;

use Padosoft\AgentSidebar\ModelSelection;

interface TokenCounter
{
    public function count(string $text, ModelSelection $model): int;
}
