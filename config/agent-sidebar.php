<?php

declare(strict_types=1);

use Padosoft\AgentSidebar\ApplicationHost;

return [
    'host' => ApplicationHost::class,
    'pages' => [],
    'tools' => [],
    'require_tool' => false,
    'instructions' => 'Help the user with this application. Answer in the language of the question.',
    'provider' => env('AGENT_SIDEBAR_PROVIDER'),
    'model' => env('AGENT_SIDEBAR_MODEL'),
    'context_ttl' => 1800,
    'turn_ttl' => 90,
    'requests_per_minute' => 12,
];
