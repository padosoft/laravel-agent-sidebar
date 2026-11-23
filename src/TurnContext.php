<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Closure;
use Laravel\Ai\Messages\Message;
use Padosoft\AgentSidebar\Models\SidebarSession;

final class TurnContext
{
    private const MAX_DEPENDENCIES = 80;

    /** @var array<string, string> */
    public array $dependencies = [];

    /** @var array<string, Source> */
    public array $citations = [];

    private int $toolCalls = 0;

    private ?string $failure = null;

    /**
     * @param  Closure(): void  $assertCurrent
     */
    public function __construct(
        public readonly Subject $subject,
        private readonly SourceAccess $access,
        private readonly Closure $assertCurrent,
    ) {}

    public function check(): void
    {
        if ($this->failure !== null) {
            throw new TurnStopped($this->failure);
        }

        ($this->assertCurrent)();

        if ($this->access->current($this->subject, $this->dependencies) === null) {
            throw new TurnStopped('sources_changed');
        }
    }

    public function stop(string $code): never
    {
        $this->failure = $code;

        throw new TurnStopped($code);
    }

    /** @param list<string> $ids */
    public function collect(array $ids): void
    {
        if (count($ids) > self::MAX_DEPENDENCIES || count($this->dependencies + array_fill_keys($ids, true)) > self::MAX_DEPENDENCIES) {
            throw new TurnStopped('tool_result_limit');
        }

        $sources = $this->access->resolve($this->subject, $ids);
        if (count($sources) !== count(array_unique($ids))) {
            throw new TurnStopped('sources_changed');
        }

        foreach ($sources as $id => $source) {
            if (isset($this->dependencies[$id]) && $this->dependencies[$id] !== $source->revision) {
                throw new TurnStopped('sources_changed');
            }

            $this->dependencies[$id] = $source->revision;
            $this->citations[$id] = $source;
        }
    }

    public function startTool(): void
    {
        $this->check();

        if (++$this->toolCalls > 6) {
            throw new TurnStopped('tool_call_limit');
        }
    }

    /** @return list<Message> */
    public function history(SidebarSession $session): array
    {
        $messages = [];
        $turns = $session->turns()->where('status', 'completed')->lazyByIdDesc();

        foreach ($turns as $turn) {
            $dependencies = $turn->dependencies ?? [];
            if (count($this->dependencies + $dependencies) > self::MAX_DEPENDENCIES
                || $this->access->current($this->subject, $dependencies) === null) {
                continue;
            }

            $this->dependencies += $dependencies;
            array_unshift($messages, new Message('user', $turn->question), new Message('assistant', $turn->answer));

            if (count($messages) === 8) {
                break;
            }
        }

        return $messages;
    }
}
