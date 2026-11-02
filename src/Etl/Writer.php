<?php

namespace App\Etl;

use App\Pipeline\Channel;
use App\Pipeline\Command\Command;
use App\Pipeline\Command\Receive;

final readonly class Writer
{
    public function __construct(private Channel $batches)
    {
    }

    /**
     * @return \Generator<int, Command, mixed, void>
     */
    public function __invoke(): \Generator
    {
        $batchCounter = $orderCounter = 0;
        while (null !== ($batch = yield new Receive($this->batches))) {
            if (!\is_array($batch) || !array_is_list($batch) || array_any($batch, static fn ($item) => !$item instanceof Order)) {
                throw new \LogicException('Batches should be lists of orders');
            }

            if ([] === $batch) {
                throw new \LogicException('Batches cannot be empty');
            }
            ++$batchCounter;
            $batchSize = \count($batch);
            $orderCounter += $batchSize;
            /** @var non-empty-list<Order> $batch */
            echo \sprintf("[writer] batch %d: %d orders (ids %d..%d)\n", $batchCounter, $batchSize, $batch[0]->id, array_last($batch)->id);
        }

        echo \sprintf("[writer] done, received %d batches, %d orders\n", $batchCounter, $orderCounter);
    }
}
