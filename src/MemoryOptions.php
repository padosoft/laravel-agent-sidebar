<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use InvalidArgumentException;

final class MemoryOptions
{
    private array $options = ['enabled' => true];

    public static function make(): self
    {
        return new self;
    }

    public function recentTokens(int $tokens): self
    {
        return $this->budget('recent_tokens', $tokens);
    }

    public function shortSummaryTokens(int $tokens): self
    {
        return $this->budget('short_tokens', $tokens);
    }

    public function longSummaryTokens(int $tokens): self
    {
        return $this->budget('long_tokens', $tokens);
    }

    /** @param list<string> $keys */
    public function wikiSections(array $keys): self
    {
        foreach ($keys as $key) {
            ContextValue::assertKey($key);
        }

        $this->options['sections'] = array_values(array_unique($keys));

        return $this;
    }

    /** @param class-string<Contracts\MemoryAuthorization> $authorizer */
    public function automatic(string $authorizer): self
    {
        $this->options['authorizer'] = $authorizer;

        return $this;
    }

    public function usingModel(?string $provider, ?string $model): self
    {
        $this->options['model'] = new ModelSelection($provider, $model);

        return $this;
    }

    /** @param class-string<Contracts\TokenCounter> $counter */
    public function countingTokensUsing(string $counter): self
    {
        $this->options['token_counter'] = $counter;

        return $this;
    }

    public function options(): array
    {
        return $this->options;
    }

    private function budget(string $key, int $tokens): self
    {
        if ($tokens < 1) {
            throw new InvalidArgumentException('A memory budget must be positive.');
        }

        $this->options[$key] = $tokens;

        return $this;
    }
}
