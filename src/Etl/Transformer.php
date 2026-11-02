<?php

namespace App\Etl;

use App\Pipeline\Channel;
use App\Pipeline\Command\Close;
use App\Pipeline\Command\Command;
use App\Pipeline\Command\Receive;
use App\Pipeline\Command\Send;

final readonly class Transformer
{
    public function __construct(private Channel $in, private Channel $out, private Channel $rejects)
    {
    }

    /**
     * @param list<mixed> $row
     */
    public function parseRow(array $row): Order
    {
        if (4 !== \count($row)) {
            throw new InvalidRowException(\sprintf('Invalid columns count (%d)', \count($row)));
        }

        [$id, $customer, $amount, $createdAt] = $row;

        // id
        if (!\is_string($id) || !ctype_digit($id)) {
            throw new InvalidRowException('Invalid order id; should be digits only');
        }

        // customer
        if (!\is_string($customer)) {
            throw new InvalidRowException('Invalid customer');
        }
        $customer = str_replace("\n", ' ', trim($customer));
        if ('' === $customer) {
            throw new InvalidRowException('Customer cannot be empty');
        }

        // amount
        if (!\is_string($amount) || !preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            throw new InvalidRowException('Invalid amount; should be numeric and > 0');
        }

        // createdAt
        if (!\is_string($createdAt) || '' === $createdAt) {
            throw new InvalidRowException('CreatedAt cannot be empty');
        }
        $format = 'Y-m-d H:i:s';
        $validatedCreatedAt = \DateTimeImmutable::createFromFormat("!$format", $createdAt);
        if (false === $validatedCreatedAt || $validatedCreatedAt->format($format) !== $createdAt) {
            throw new InvalidRowException('CreatedAt format is invalid');
        }

        return new Order(
            (int) $id,
            $customer,
            (float) $amount,
            $validatedCreatedAt
        );
    }

    /**
     * @return \Generator<int, Command, mixed, void>
     */
    public function __invoke(): \Generator
    {
        while (null !== ($row = yield new Receive($this->in))) {
            if (!\is_array($row) || !array_is_list($row)) {
                throw new \LogicException('Row should be a list');
            }
            try {
                $order = $this->parseRow($row);
            } catch (InvalidRowException $e) {
                yield new Send($this->rejects, new Reject($row, $e));
                continue;
            }
            yield new Send($this->out, $order);
        }

        yield new Close($this->out);
        yield new Close($this->rejects);
    }
}
