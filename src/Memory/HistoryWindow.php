<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Laravel\Ai\Messages\Message;
use Padosoft\AgentSidebar\Models\SidebarSession;
use Padosoft\AgentSidebar\ModelSelection;
use Padosoft\AgentSidebar\TurnContext;

final readonly class HistoryWindow
{
    public function __construct(private TokenBudget $budget) {}

    /** @return array{list<Message>, int|null} Messages and earliest included turn. */
    public function recent(SidebarSession $session, TurnContext $context, ModelSelection $model, int $after): array
    {
        $messages = [];
        $first = null;
        $remaining = (int) config('agent-sidebar.memory.recent_tokens', 2000);

        foreach ($session->turns()->where('status', 'completed')->where('id', '>', $after)->lazyByIdDesc() as $turn) {
            $question = $turn->question;
            $answer = $turn->answer;
            $cost = $this->budget->count($question, $model) + $this->budget->count($answer, $model) + 8;
            if ($cost > $remaining) {
                if ($messages !== [] || $remaining <= 8) {
                    break;
                }

                $part = intdiv($remaining - 8, 2);
                $question = $this->budget->fit($question, $part, $model);
                $answer = $this->budget->fit($answer, $part, $model);
                $cost = $remaining;
            }

            if (! $context->includeDependencies($turn->dependencies ?? [])) {
                continue;
            }

            array_unshift($messages, new Message('user', $question), new Message('assistant', $answer));
            $first = (int) $turn->id;
            $remaining -= $cost;
            if ($remaining <= 8) {
                break;
            }
        }

        return [$messages, $first];
    }
}
