<?php

namespace App\Stream;

final readonly class Message
{
    public function __construct(
        public string $data,  // raw payload, not decoded yet
        public ?string $type = null, // SSE "event:" value, e.g. "content_block_delta"; null for NDJSON
    ) {
    }
}
