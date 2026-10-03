<?php

namespace App\Stream\Decoder;

use App\Stream\Event;
use App\Stream\Message;

class DemoMessageDecoder implements MessageDecoder
{
    /**
     * Text that could be held when e.g. a partial [DONE] marker is echoed.
     */
    private string $held = '';

    /**
     * Our demo echoes "[DONE]" as its last bytes, and the splitter doesn't frame plain text,
     * so the marker can arrive split across messages. Hold back any possible start of it
     * until the next message settles the question.
     */
    public function decode(Message $message): \Generator
    {
        $text = $this->held.$message->data;
        $this->held = '';

        if (str_ends_with($text, '[DONE]')) {
            yield new Event(source: '', body: substr($text, 0, -\strlen('[DONE]')), done: true);

            return;
        }

        if (preg_match('/\[D?O?N?E?\z/', $text, $matches, \PREG_OFFSET_CAPTURE)) {
            $this->held = $matches[0][0];
            $text = substr($text, 0, $matches[0][1]);
        }

        if ('' !== $text) {
            yield new Event(source: '', body: $text);
        }
    }
}
