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

    // Routing table — handlers are added in later tasks.
    // For now: GET / returns a placeholder; everything else 404s.
    if ($method === 'GET' && $path === '/') {
        echo 'shortener boot ok';
        return;
    }
    not_found();
}
