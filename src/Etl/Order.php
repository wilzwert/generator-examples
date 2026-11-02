<?php

namespace App\Etl;

final readonly class Order
{
    public function __construct(
        public int $id,
        public string $customer,
        public float $amount,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
