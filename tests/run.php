<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/GatewayTest.php';
require __DIR__ . '/WebhookControllerTest.php';

$tests = array_filter(get_defined_functions()['user'], static function ($name) {
    return strpos($name, 'test_edd_') === 0;
});

$count = 0;
foreach ($tests as $test) {
    $test();
    $count++;
    echo 'PASS ' . $test . PHP_EOL;
}

echo 'OK ' . $count . ' tests' . PHP_EOL;
