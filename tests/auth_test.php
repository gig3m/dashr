<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

it('bearer_token_from_header parses a valid header', function () {
    assert_eq('abc123', bearer_token_from_header('Bearer abc123'));
});

it('bearer_token_from_header is case-insensitive on the scheme', function () {
    assert_eq('xyz', bearer_token_from_header('bearer xyz'));
    assert_eq('xyz', bearer_token_from_header('BEARER xyz'));
});

it('bearer_token_from_header returns null when missing', function () {
    assert_eq(null, bearer_token_from_header(''));
    assert_eq(null, bearer_token_from_header('Basic foo'));
});

it('check_bearer compares constant-time and rejects empty config', function () {
    assert_true(check_bearer('secret', 'secret'));
    assert_true(!check_bearer('secret', 'wrong'));
    assert_true(!check_bearer('', 'anything'));
    assert_true(!check_bearer('secret', ''));
});

it('verify_csrf returns true only when tokens match and are non-empty', function () {
    assert_true(verify_csrf('tok', 'tok'));
    assert_true(!verify_csrf('tok', 'other'));
    assert_true(!verify_csrf('', ''));
    assert_true(!verify_csrf('tok', ''));
});
