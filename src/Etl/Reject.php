<?php

namespace App\Etl;

final readonly class Reject
{
    /**
     * @param list<mixed> $row
     */
    public function __construct(public array $row, public InvalidRowException $exception)
    {
    }
}
