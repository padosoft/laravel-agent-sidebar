<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Models;

use Illuminate\Database\Eloquent\Model;

final class SidebarTurn extends Model
{
    protected $table = 'agent_sidebar_turns';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'question' => 'encrypted',
            'answer' => 'encrypted',
            'dependencies' => 'encrypted:array',
            'citation_ids' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
