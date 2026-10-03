<?php

namespace App\Stream\Demo;

/**
 * Stands in for an LLM provider: yields a canned answer in small random
 * chunks, pausing between them the way a real token stream would.
 */
final class DemoLlm
{
    /** @var list<string> */
    public const TEXTS = [
        'Generators let a function produce values lazily, one at a time, instead of building the whole array in memory. '
        .'When you call a function that contains yield, PHP does not run its body right away. It returns a Generator object '
        .'that you can iterate with foreach. Each yield pauses the function and hands a value to the caller, and the function '
        .'resumes exactly where it stopped on the next iteration. This makes them a good fit for reading large files line by line, '
        .'paginating through database results, or processing a stream of data without holding everything at once. Generators '
        .'can also receive values back with send(), and they can delegate to other generators with yield from, which keeps '
        .'pipelines short and readable. The main trade-off is that a generator can only be consumed once, so if you need to '
        .'traverse the same data twice, you have to create it again.',

        'HTTP/1.1 supports chunked transfer encoding, which lets a server start sending a response before it knows its total '
        .'length. Instead of a Content-Length header, each chunk is prefixed with its size in hexadecimal, and a zero-length '
        .'chunk marks the end of the body. This is what makes streaming possible: the server can send a sentence, flush it, '
        .'and keep the connection open while more data is produced. Proxies and load balancers sometimes buffer responses '
        .'by default, which breaks the illusion, so it helps to disable buffering explicitly with headers such as '
        .'X-Accel-Buffering in nginx, and to flush the output buffers after every write on the application side. Clients '
        .'that read the body incrementally will then see text appear as soon as it is generated, instead of waiting for the '
        .'whole response to finish.',

        'Running several requests at the same time is mostly about not waiting. With curl_multi, you register many handles, '
        .'call curl_multi_exec until nothing is pending, and collect the results as they complete. Each transfer runs on '
        .'its own socket, so a slow response does not block a fast one. Combining this with generators is a natural fit: '
        .'every handle can be wrapped in a generator that yields each chunk as it arrives, and a loop can pull from all of '
        .'them in turn, printing whatever is ready. The result feels like several answers being typed out side by side, '
        .'even though the whole thing runs in a single PHP process. Keep an eye on the number of concurrent connections, '
        .'because a provider will happily rate-limit you long before your own machine runs out of resources.',

        'A good cache key is stable, specific and cheap to compute. It should include every input that changes the result, '
        .'and nothing that changes without affecting the result. For example, when caching the output of a language model, '
        .'the model name, the prompt, the temperature and the system instructions all matter, while a request identifier or '
        .'a timestamp does not. Normalizing whitespace and sorting keys in structured inputs avoids storing the same answer '
        .'under several keys. Expiration is the other half of the problem. Content that rarely changes can live for hours, '
        .'while anything tied to live data needs a short time to live or an explicit invalidation when the source is updated. '
        .'Measure the hit rate before tuning anything, because a cache that is almost never hit adds latency and memory '
        .'without giving anything back.',
    ];

    public static function pick(?int $index = null): string
    {
        return self::TEXTS[$index ?? random_int(0, \count(self::TEXTS) - 1)];
    }

    /**
     * Yields the text 1 to 3 words at a time, sleeping a random 20 to 80 ms between chunks.
     *
     * @return \Generator<int, string>
     */
    public static function stream(
        string $text,
        int $minWords = 1,
        int $maxWords = 3,
        int $minDelayMs = 20,
        int $maxDelayMs = 80,
    ): \Generator {
        // Each token keeps its trailing whitespace, so joining chunks rebuilds the original text.
        $tokens = preg_split('/(?<=\s)/u', $text, -1, \PREG_SPLIT_NO_EMPTY)
            ?: throw new \RuntimeException('Could not split text into tokens.');
        $count = \count($tokens);

        for ($i = 0; $i < $count;) {
            $size = random_int($minWords, $maxWords);
            yield implode('', \array_slice($tokens, $i, $size));
            $i += $size;

            usleep(random_int($minDelayMs, $maxDelayMs) * 1000);
        }

        yield '[DONE]';
    }
}
