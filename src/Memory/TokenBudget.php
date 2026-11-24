<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Padosoft\AgentSidebar\ModelSelection;

final class TokenBudget
{
    public function count(string $text, ModelSelection $model): int
    {
        $counter = config('agent-sidebar.memory.token_counter');

        return $counter ? max(0, app($counter)->count($text, $model)) : strlen($text);
    }

    public function fit(string $text, int $limit, ModelSelection $model): string
    {
        if ($this->count($text, $model) <= $limit) {
            return $text;
        }

        $low = 0;
        $high = strlen($text);
        $candidate = '';

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $prefix = mb_strcut($text, 0, $middle, 'UTF-8');
            if ($this->count($prefix, $model) <= $limit) {
                $candidate = $prefix;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $candidate;
    }
}
