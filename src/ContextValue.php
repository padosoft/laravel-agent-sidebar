<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use InvalidArgumentException;

final readonly class ContextValue
{
    /** @param list<string> $sourceIds */
    public function __construct(public string $text, public array $sourceIds = [])
    {
        if (strlen($text) > 16000 || ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid sidebar context or memory value.');
        }
    }

    public static function assertKey(string $key): void
    {
        if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', $key)) {
            throw new InvalidArgumentException('Invalid sidebar memory key.');
        }
    }
}
