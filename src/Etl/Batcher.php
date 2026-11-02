<?php

namespace App\Etl;

use App\Pipeline\Channel;
use App\Pipeline\Command\Close;
use App\Pipeline\Command\Command;
use App\Pipeline\Command\Receive;
use App\Pipeline\Command\Send;

final readonly class Batcher
{
    public function __construct(private int $batchSize, private Channel $orders, private Channel $batches)
    {
        if ($this->batchSize < 1) {
            throw new \LogicException('Batch size cannot be < 1');
        }
    }

    /**
     * @return \Generator<int, Command, mixed, void>
     */
    public function __invoke(): \Generator
    {
        /** @var list<Order> $buffer */
        $buffer = [];
        while (null !== ($order = yield new Receive($this->orders))) {
            if (!$order instanceof Order) {
                throw new \LogicException('Orders should be instances of the Order class');
            }
            $buffer[] = $order;
            if (\count($buffer) >= $this->batchSize) {
                yield new Send($this->batches, $buffer);
                $buffer = [];
            }
        }
        if ([] !== $buffer) {
            yield new Send($this->batches, $buffer);
        }
        yield new Close($this->batches);
    }
}
