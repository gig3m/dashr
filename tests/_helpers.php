<?php
declare(strict_types=1);

// Tiny test runner. Each test file calls it('name', function() {...}).
// tests/run.php requires all *_test.php files and prints a summary.

$GLOBALS['__tests'] = [];
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function it(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new RuntimeException("expected $e, got $a" . ($msg ? " — $msg" : ''));
    }
}

function assert_true(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException('expected true' . ($msg ? " — $msg" : ''));
    }
}

function assert_throws(callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('expected exception' . ($msg ? " — $msg" : ''));
}

function run_tests(): int
{
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try {
            $fn();
            $GLOBALS['__pass']++;
            echo "  ok  $name\n";
        } catch (Throwable $e) {
            $GLOBALS['__fail']++;
            echo "  FAIL $name\n      " . $e->getMessage() . "\n";
        }
    }
    $p = $GLOBALS['__pass'];
    $f = $GLOBALS['__fail'];
    echo "\n$p passed, $f failed\n";
    return $f === 0 ? 0 : 1;
}
