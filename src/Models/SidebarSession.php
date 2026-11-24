<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SidebarSession extends Model
{
    protected $table = 'agent_sidebar_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['memory_permit' => 'encrypted:array', 'short_rebuild' => 'boolean'];
    }

    public function turns(): HasMany
    {
        return $this->hasMany(SidebarTurn::class, 'session_id');
    }
}
