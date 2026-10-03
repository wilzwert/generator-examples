<?php

namespace App\Stream;

final readonly class Event
{
    public function __construct(
        public string $source,
        public string $body,
        public bool $done = false,
        public ?string $error = null,
    ) {
    }
}
