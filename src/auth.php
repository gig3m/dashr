<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Configure secure session cookie attributes. Call before session_start().
 */
function configure_session(): void
{
    ini_set('session.cookie_httponly', '1');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

/** Redirect to login if not authed. */
function require_admin(): void
{
    if (!is_admin()) {
        header('Location: /admin');
        exit;
    }
}

/** Get-or-create the per-session CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(string $expected, string $actual): bool
{
    if ($expected === '' || $actual === '') {
        return false;
    }
    return hash_equals($expected, $actual);
}

/** Extract token from `Authorization: Bearer X` header, case-insensitive. */
function bearer_token_from_header(string $header): ?string
{
    if ($header === '') {
        return null;
    }
    if (!preg_match('/^bearer\s+(\S+)/i', $header, $m)) {
        return null;
    }
    return $m[1];
}

function check_bearer(string $configured, string $supplied): bool
{
    if ($configured === '' || $supplied === '') {
        return false;
    }
    return hash_equals($configured, $supplied);
}

/** API gate: validate Bearer token or return 401 JSON and exit. */
function require_bearer(): void
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $tok = bearer_token_from_header($hdr) ?? '';
    if (!check_bearer((string) env('API_TOKEN', ''), $tok)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
}
