<?php
declare(strict_types=1);

/**
 * Render a view with $vars in scope. Includes _layout.php if it exists; the view
 * must populate $title and the layout echoes $content.
 */
function view(string $name, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../views/' . $name . '.php';
    return (string) ob_get_clean();
}

function render(string $name, array $vars = []): void
{
    $content = view($name, $vars);
    $title = $vars['title'] ?? 'southside.cc';
    require __DIR__ . '/../views/_layout.php';
}

function not_found(): void
{
    http_response_code(404);
    render('notfound', ['title' => 'Not Found']);
    exit;
}

function dispatch(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

    if ($method === 'GET' && $path === '/') {
        route_enter_form(); return;
    }
    if ($method === 'POST' && $path === '/go') {
        route_go(); return;
    }
    if ($method === 'GET' && preg_match('#^/([0-9]{6}|[0-9]{3}-[0-9]{3})$#', $path, $m)) {
        route_redirect($m[1]); return;
    }
    not_found();
}

function route_enter_form(string $error = ''): void
{
    render('enter', ['title' => 'southside.cc', 'error' => $error]);
}

function route_go(): void
{
    $code = normalize_code((string) ($_POST['code'] ?? ''));
    if ($code === null) {
        route_enter_form('Enter a 6-digit code.'); return;
    }
    $stmt = get_db()->prepare('SELECT url FROM links WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) {
        route_enter_form('That code isn\'t in the directory.'); return;
    }
    header('Location: ' . $row['url'], true, 302);
}

function route_redirect(string $rawCode): void
{
    $code = normalize_code($rawCode);
    if ($code === null) {
        not_found();
    }
    $stmt = get_db()->prepare('SELECT url FROM links WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) {
        not_found();
    }
    header('Location: ' . $row['url'], true, 302);
}
