<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Models;

use Illuminate\Database\Eloquent\Model;

final class MemoryEntry extends Model
{
    protected $table = 'agent_sidebar_memory';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'dependencies' => 'encrypted:array'];
    }
}
