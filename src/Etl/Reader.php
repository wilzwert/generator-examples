<?php

namespace App\Etl;

use App\Pipeline\Channel;
use App\Pipeline\Command\Close;
use App\Pipeline\Command\Command;
use App\Pipeline\Command\Send;

final readonly class Reader
{
    public function __construct(private string $path, private Channel $out)
    {
    }

    /**
     * @return \Generator<int, Command, mixed, void>
     */
    public function __invoke(): \Generator
    {
        if (false === ($fp = fopen($this->path, 'r'))) {
            throw new \RuntimeException('Unable to open the file.');
        }

        try {
            $first = fgetcsv(stream: $fp, escape: '');
            if (false === $first) {
                throw new \RuntimeException('File is empty');
            }

            if (['order_id', 'customer', 'amount', 'createdAt'] !== $first) {
                throw new \RuntimeException('Wrong file format');
            }

            while (false !== ($line = fgetcsv(stream: $fp, escape: ''))) {
                if ([null] !== $line) {
                    yield new Send($this->out, $line);
                }
            }

            // properly close the channel
            yield new Close($this->out);
        } finally {
            fclose($fp);
        }
    }
}
