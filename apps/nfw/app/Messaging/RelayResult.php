<?php

namespace App\Messaging;

final class RelayResult
{
    public function __construct(
        public readonly int $published,
        public readonly int $failed,
        public readonly ?string $error,
    ) {}

    public function idle(): bool
    {
        return $this->published === 0 && $this->failed === 0;
    }
}
