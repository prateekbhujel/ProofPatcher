<?php

declare(strict_types=1);

require __DIR__ . '/../src/OrderTotal.php';

$cases = [
    'empty order' => [[], 0],
    'single item' => [[['unit_price' => 100, 'quantity' => 2]], 200],
    'zero quantity' => [[['unit_price' => 100, 'quantity' => 0]], 0],
    'mixed quantities' => [[['unit_price' => 100, 'quantity' => 0], ['unit_price' => 50, 'quantity' => 2]], 100],
];

$failed = false;
foreach ($cases as $name => [$items, $expected]) {
    $actual = orderTotal($items);
    if ($actual !== $expected) {
        fwrite(STDERR, "$name: expected $expected, got $actual\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo "All order total cases passed.\n";
