<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/codes.php';

it('normalize_code accepts plain 6 digits', function () {
    assert_eq('123456', normalize_code('123456'));
});

it('normalize_code accepts hyphenated form', function () {
    assert_eq('123456', normalize_code('123-456'));
});

it('normalize_code strips spaces and dots', function () {
    assert_eq('123456', normalize_code('123 456'));
    assert_eq('123456', normalize_code('123.456'));
});

it('normalize_code preserves leading zeros', function () {
    assert_eq('000123', normalize_code('000-123'));
});

it('normalize_code returns null for wrong length', function () {
    assert_eq(null, normalize_code('12345'));
    assert_eq(null, normalize_code('1234567'));
});

it('normalize_code returns null for non-digit content', function () {
    assert_eq(null, normalize_code('abc-def'));
    assert_eq(null, normalize_code(''));
});

it('format_code inserts hyphen', function () {
    assert_eq('123-456', format_code('123456'));
    assert_eq('000-001', format_code('000001'));
});

it('generate_code returns a 6-digit string when no collision', function () {
    $code = generate_code(fn($c) => false);
    assert_true(strlen($code) === 6, "got '$code'");
    assert_true(ctype_digit($code), "got '$code'");
});

it('generate_code retries on collision', function () {
    $calls = 0;
    $exists = function ($c) use (&$calls) {
        $calls++;
        return $calls < 3; // first two attempts collide
    };
    $code = generate_code($exists);
    assert_eq(3, $calls);
    assert_true(strlen($code) === 6);
});

it('generate_code throws after 10 collisions', function () {
    assert_throws(function () {
        generate_code(fn($c) => true);
    });
});
