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

    // Admin auth (added in Task 9)
    if ($method === 'GET'  && $path === '/admin')        { route_admin_root();   return; }
    if ($method === 'POST' && $path === '/admin/login')  { route_admin_login();  return; }
    if ($method === 'POST' && $path === '/admin/logout') { route_admin_logout(); return; }

    // Admin link management (all require auth, enforced inside handlers)
    if ($method === 'GET'  && $path === '/admin/links')  { route_admin_links_list();   return; }
    if ($method === 'POST' && $path === '/admin/links')  { route_admin_links_create(); return; }
    if ($method === 'POST' && preg_match('#^/admin/links/([0-9]{6})/delete$#', $path, $m)) {
        route_admin_links_delete($m[1]);
        return;
    }

    // API
    if ($method === 'POST' && $path === '/api/links') { route_api_create(); return; }

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

function route_admin_root(): void
{
    if (is_admin()) {
        header('Location: /admin/links', true, 302);
        return;
    }
    render('login', ['title' => 'admin login', 'error' => '']);
}

function route_admin_login(): void
{
    $hash = (string) env('ADMIN_PASSWORD_HASH', '');
    $pw = (string) ($_POST['password'] ?? '');
    if ($hash === '' || !password_verify($pw, $hash)) {
        // Tiny delay to discourage rapid guessing.
        usleep(250_000);
        http_response_code(401);
        render('login', ['title' => 'admin login', 'error' => 'Incorrect password.']);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    header('Location: /admin/links', true, 302);
}

function route_admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /', true, 302);
}

function route_admin_links_list(string $error = '', string $created = ''): void
{
    require_admin();
    $rows = get_db()->query('SELECT code, url, created_at FROM links ORDER BY created_at DESC')->fetchAll();
    render('admin', [
        'title'   => 'admin',
        'rows'    => $rows,
        'csrf'    => csrf_token(),
        'error'   => $error,
        'created' => $created,
    ]);
}

function route_admin_links_create(): void
{
    require_admin();
    if (!verify_csrf($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        route_admin_links_list('Invalid form submission.', '');
        return;
    }
    $url = trim((string) ($_POST['url'] ?? ''));
    $rawCode = trim((string) ($_POST['code'] ?? ''));

    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        route_admin_links_list('URL must be a valid http(s) URL.', '');
        return;
    }

    $db = get_db();
    if ($rawCode !== '') {
        $code = normalize_code($rawCode);
        if ($code === null) {
            route_admin_links_list('Custom code must be 6 digits (XXX-XXX).', '');
            return;
        }
        $stmt = $db->prepare('SELECT 1 FROM links WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn()) {
            route_admin_links_list("Code " . format_code($code) . " is already taken.", '');
            return;
        }
    } else {
        $exists = function (string $c) use ($db): bool {
            $s = $db->prepare('SELECT 1 FROM links WHERE code = ?');
            $s->execute([$c]);
            return (bool) $s->fetchColumn();
        };
        $code = generate_code($exists);
    }

    $ins = $db->prepare('INSERT INTO links (code, url, created_at) VALUES (?, ?, ?)');
    $ins->execute([$code, $url, time()]);
    route_admin_links_list('', $code);
}

function route_admin_links_delete(string $code): void
{
    require_admin();
    if (!verify_csrf($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        route_admin_links_list('Invalid form submission.', '');
        return;
    }
    $stmt = get_db()->prepare('DELETE FROM links WHERE code = ?');
    $stmt->execute([$code]);
    header('Location: /admin/links', true, 302);
}

function route_api_create(): void
{
    require_bearer();
    header('Content-Type: application/json');

    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        return;
    }

    $url = trim((string) ($body['url'] ?? ''));
    $rawCode = trim((string) ($body['code'] ?? ''));

    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        http_response_code(400);
        echo json_encode(['error' => 'url must be a valid http(s) URL']);
        return;
    }

    $db = get_db();

    if ($rawCode !== '') {
        $code = normalize_code($rawCode);
        if ($code === null) {
            http_response_code(400);
            echo json_encode(['error' => 'code must be 6 digits']);
            return;
        }
        $s = $db->prepare('SELECT 1 FROM links WHERE code = ?');
        $s->execute([$code]);
        if ($s->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error' => 'code already taken']);
            return;
        }
    } else {
        $exists = function (string $c) use ($db): bool {
            $s = $db->prepare('SELECT 1 FROM links WHERE code = ?');
            $s->execute([$c]);
            return (bool) $s->fetchColumn();
        };
        $code = generate_code($exists);
    }

    $ins = $db->prepare('INSERT INTO links (code, url, created_at) VALUES (?, ?, ?)');
    $ins->execute([$code, $url, time()]);

    $base = rtrim((string) env('BASE_URL', ''), '/');
    echo json_encode([
        'code'      => format_code($code),
        'short_url' => $base . '/' . format_code($code),
    ]);
}
