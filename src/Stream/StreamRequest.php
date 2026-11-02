<?php

namespace App\Stream;

readonly class StreamRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $url,
        public HttpMethod $httpMethod,
        public array $headers,
        public string $body,
    ) {
    }
}
