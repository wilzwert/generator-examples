<?php

namespace App\Etl;

use App\Pipeline\Channel;
use App\Pipeline\Command\Command;
use App\Pipeline\Command\Receive;
use App\Support\Format;

final readonly class RejectLogger
{
    public function __construct(private Channel $rejects)
    {
    }

    /**
     * @return \Generator<int, Command, mixed, void>
     */
    public function __invoke(): \Generator
    {
        $rejectsCount = 0;

        while (null !== ($reject = yield new Receive($this->rejects))) {
            if (!$reject instanceof Reject) {
                throw new \LogicException('Rejects should be instances of Reject');
            }

            ++$rejectsCount;
            echo \sprintf("[rejects] row [%s]: reason %s\n", Format::value($reject->row[0] ?? null), $reject->exception->getMessage());
        }

        echo \sprintf("[rejects] done with %d rejects\n", $rejectsCount);
    }
}
