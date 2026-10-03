<?php

namespace App\Stream;

use App\Stream\Provider\ProviderRegistry;

/**
 * Reads several provider streams at once with curl_multi and yields their events
 * in the order they arrive. Each event carries the name of the provider it came from,
 * and each stream ends with exactly one event marked done (with an error if it failed).
 */
final class MultiStream
{
    /**
     * Keep at most this much of an HTTP error body for the error message.
     */
    private const int MAX_ERROR_BODY_BYTES = 1024;

    private readonly ProviderRegistry $registry;

    public function __construct(
        private readonly int $timeoutSeconds = 60,
    ) {
        $this->registry = new ProviderRegistry();
    }

    /**
     * @param list<string> $providerNames
     *
     * @return \Generator<int, Event>
     */
    public function run(array $providerNames, string $userMessage): \Generator
    {
        if (0 === \count($providerNames)) {
            throw new \LogicException('You must provide at least one provider');
        }

        $providers = array_filter(
            $this->registry->getProviders(),
            static fn (string $name): bool => \in_array($name, $providerNames, true),
            \ARRAY_FILTER_USE_KEY
        );

        if (!\count($providers)) {
            throw new \LogicException('Selected providers are unknown.');
        }

        $multi = curl_multi_init();
        /** @var array<string, \CurlHandle> $handles */
        $handles = [];
        /** @var array<string, StreamExchange> $exchanges */
        $exchanges = [];
        /** @var array<string, string> $errorBodies */
        $errorBodies = [];

        try {
            foreach ($providers as $name => $provider) {
                $exchange = new StreamExchange($name, $provider, $userMessage);
                $errorBodies[$name] = '';

                $handle = curl_init();
                curl_setopt_array($handle, $this->curlOptions($exchange->request) + [
                    // Bytes go to the write callback instead of accumulating in curl.
                    \CURLOPT_RETURNTRANSFER => false,
                    \CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $data) use ($exchange, $name, &$errorBodies): int {
                        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
                        if ($status < 200 || $status >= 300) {
                            // keep the body for the error message, don't give it to the parser
                            $errorBodies[$name] = substr($errorBodies[$name].$data, 0, self::MAX_ERROR_BODY_BYTES);
                        } else {
                            $exchange->receiveBytes($data);
                        }

                        // curl expects the number of bytes handled; anything else aborts the transfer.
                        return \strlen($data);
                    },
                    \CURLOPT_TIMEOUT => $this->timeoutSeconds,
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[$name] = $handle;
                $exchanges[$name] = $exchange;
            }

            $running = \count($handles);
            do {
                curl_multi_exec($multi, $running);

                foreach ($exchanges as $exchange) {
                    yield from $exchange->getEvents();
                }

                /** @var array{msg: int, result: int, handle: \CurlHandle}|false $info */
                while ($info = curl_multi_info_read($multi)) {
                    $name = array_search($info['handle'], $handles, true);
                    if (false === $name) {
                        continue;
                    }

                    yield from $exchanges[$name]->finish($this->transportError($info['handle'], $info['result'], $errorBodies[$name]));
                }

                if ($running > 0) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running > 0);
        } finally {
            // Also runs when the caller stops iterating, e.g. after a client disconnect.
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function curlOptions(StreamRequest $request): array
    {
        $headers = [];
        foreach ($request->headers as $headerName => $value) {
            $headers[] = $headerName.': '.$value;
        }

        $options = [
            \CURLOPT_URL => $request->url,
            \CURLOPT_CUSTOMREQUEST => $request->httpMethod->value,
            \CURLOPT_HTTPHEADER => $headers,
        ];

        // Setting POSTFIELDS, even empty, would turn a GET into a POST.
        if ('' !== $request->body) {
            $options[\CURLOPT_POSTFIELDS] = $request->body;
        }

        return $options;
    }

    /**
     * A transfer can "succeed" for curl and still be an HTTP error (bad key, overloaded...).
     */
    private function transportError(\CurlHandle $handle, int $curlResult, string $errorBody): ?string
    {
        if (\CURLE_OK !== $curlResult) {
            return curl_strerror($curlResult);
        }

        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        if ($status < 200 || $status >= 300) {
            return trim(\sprintf('HTTP %d: %s', $status, $errorBody));
        }

        return null;
    }
}
