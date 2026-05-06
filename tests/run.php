<?php
declare(strict_types=1);

require __DIR__ . '/_helpers.php';

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    echo basename($file) . "\n";
    require $file;
}

exit(run_tests());
