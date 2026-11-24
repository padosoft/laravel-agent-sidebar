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
    'inference_timeout' => 25,
    'requests_per_minute' => 12,
    'memory' => [
        'enabled' => false,
        'recent_tokens' => 2000,
        'short_tokens' => 700,
        'long_tokens' => 500,
        'sections' => [],
        'queue_connection' => env('AGENT_SIDEBAR_MEMORY_QUEUE'),
    ],
    'wiki' => [
        'disk' => env('AGENT_SIDEBAR_WIKI_DISK', 'local'),
        'prefix' => 'agent-sidebar/wiki',
    ],
    'mcp_servers' => [],
    'pact' => [
        'enabled' => false,
        'authorizer' => null,
        'clarification_key' => 'agent-clarification',
    ],
];
