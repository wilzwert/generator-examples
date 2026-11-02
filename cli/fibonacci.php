<?php

$maxTerms = (int) ($argv[1] ?? 100);

/**
 * @return Generator<int, int, mixed, void>
 */
function getFibonacci(): \Generator {
    $i = 0;
    $k = 1;
    yield $k; // First term
    while (true) {
        $k = $i + $k;
        $i = $k - $i;
        yield $k; // nex terms, one by one
    }
}

$i = 0;
foreach (getFibonacci() as $term) {
    ++$i;
    printf("Term %d : %f\n", $i, $term);
    if ($maxTerms > 0 && $i >= $maxTerms) {
        break;
    }
}