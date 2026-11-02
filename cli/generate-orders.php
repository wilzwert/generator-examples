<?php

use Random\Engine\Mt19937;
use Random\Randomizer;

$rowsToGenerate = (int) ($argv[1] ?? 1000);
$fp = fopen(__DIR__.'/../var/orders.csv', 'w');

if (false === $fp) {
    fwrite(\STDERR, 'File is not writeable');
    exit(1);
}

function customerId(int $i): string
{
    return sprintf('Customer-%d', $i + 1);
}

function randomAmount(Randomizer $random): float
{
    return $random->getInt(1, 50000) / 100;
}

function randomDate(Randomizer $random): string
{
    return gmdate('Y-m-d H:i:s', $random->getInt(1666051200, 1789948800));
}

fputcsv($fp, ['order_id', 'customer', 'amount', 'createdAt'], escape: '');

$random = new Randomizer(new Mt19937(42));

for ($i = 0; $i < $rowsToGenerate; ++$i) {
    $rand = $random->getInt(1, 1000);
    $order = match (true) {
        // wrong column count
        $rand <= 10 => [$i + 1, customerId($i)],
        // negative amount
        $rand <= 20 => [$i + 1, customerId($i), -1, randomDate($random)],
        // invalid amount
        $rand <= 30 => [$i + 1, customerId($i), 'N/A', randomDate($random)],
        // impossible date
        $rand <= 40 => [$i + 1, customerId($i), randomAmount($random), '2026-02-30 12:00:00'],
        // newline
        $rand <= 50 => [$i + 1, sprintf("Customer\n%d", $i + 1), randomAmount($random), randomDate($random)],
        // normal line
        default => [$i + 1, customerId($i), randomAmount($random), randomDate($random)],
    };
    fputcsv($fp, $order, escape: '');
}

fclose($fp);
