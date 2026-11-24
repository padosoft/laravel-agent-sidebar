<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class UpdateMemory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [15, 60, 180];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $sessionId, public readonly string $generation) {}

    public function uniqueId(): string
    {
        return $this->sessionId.':'.$this->generation;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(15)->expireAfter(120)];
    }

    public function handle(MemoryWorker $worker): void
    {
        $worker->update($this->sessionId, $this->generation);
    }
}
