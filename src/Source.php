<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use InvalidArgumentException;

final readonly class Source
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public string $revision = '',
    ) {
        if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_.:-]{0,159}\z/', $id)) {
            throw new InvalidArgumentException('Invalid sidebar source identifier.');
        }

        if (! str_starts_with($url, '/') || str_starts_with($url, '//')
            || str_contains($url, chr(92)) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('Sidebar source URLs must be local paths.');
        }
    }

    /** @return array{id: string, title: string, url: string} */
    public function citation(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'url' => $this->url];
    }
}
