# southside.cc URL Shortener Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a self-hosted PHP + SQLite URL shortener with 6-digit numeric codes (XXX-XXX), admin web UI, and bearer-token API, deployed to a Laravel Forge box.

**Architecture:** Plain PHP front controller (`public/index.php`) dispatches to handlers in `src/routes.php`. Modules in `src/` are small and single-responsibility: env loader, db (PDO + auto-migration), codes (normalize/format/generate), auth (session + bearer + CSRF). Views are small PHP templates. SQLite file lives outside the web root. Zero Composer dependencies; tiny custom test runner.

**Tech Stack:** PHP 8.2+, SQLite (via PDO), no framework, no Composer.

---

## File Layout

To be created:

```
shortener/
├── public/
│   └── index.php          # front controller — dispatcher
├── src/
│   ├── env.php            # .env loader
│   ├── db.php             # PDO + migration
│   ├── codes.php          # normalize_code, format_code, generate_code
│   ├── auth.php           # is_admin, require_admin, require_bearer, csrf_token, verify_csrf
│   └── routes.php         # one handler function per route
├── views/
│   ├── _layout.php        # shared HTML wrapper
│   ├── enter.php          # public code entry
│   ├── notfound.php       # 404
│   ├── login.php          # admin login
│   └── admin.php          # admin link list + create form
├── tests/
│   ├── _helpers.php       # tiny it() / assert_eq() runner
│   ├── codes_test.php
│   ├── auth_test.php
│   └── integration_test.php
├── data/
│   └── .gitkeep           # ensures dir is in repo; .db files gitignored
├── .env.example
├── .gitignore             # already exists
└── DEPLOY.md              # Forge setup notes
```

---

## Task 1: Scaffolding

**Files:**
- Create: `public/.gitkeep`
- Create: `src/.gitkeep`
- Create: `views/.gitkeep`
- Create: `tests/.gitkeep`
- Create: `data/.gitkeep`
- Create: `.env.example`
- Modify: `.gitignore` (already created during brainstorming — verify contents)

- [ ] **Step 1: Create directory structure**

```bash
cd /Users/kylearrington/Projects/southside-shortener
mkdir -p public src views tests data
touch public/.gitkeep src/.gitkeep views/.gitkeep tests/.gitkeep data/.gitkeep
```

- [ ] **Step 2: Verify `.gitignore`**

Run: `cat .gitignore`
Expected output:
```
.env
data/links.db
data/test.db
.DS_Store
```

If different, overwrite with the above contents.

- [ ] **Step 3: Write `.env.example`**

Create `.env.example` with this exact content:

```
# Bcrypt hash of the admin password.
# Generate with: php -r 'echo password_hash("CHANGE_ME", PASSWORD_BCRYPT) . "\n";'
ADMIN_PASSWORD_HASH=

# Random 32+ character bearer token for the API.
# Generate with: php -r 'echo bin2hex(random_bytes(24)) . "\n";'
API_TOKEN=

# Public base URL, used to build short_url in API responses. No trailing slash.
BASE_URL=https://southside.cc
```

- [ ] **Step 4: Commit**

```bash
git add public src views tests data .env.example
git commit -m "Scaffold project directories and .env.example"
```

---

## Task 2: Test Harness

**Files:**
- Create: `tests/_helpers.php`
- Create: `tests/run.php`

- [ ] **Step 1: Write the test helpers**

Create `tests/_helpers.php`:

```php
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
```

- [ ] **Step 2: Write the test runner**

Create `tests/run.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/_helpers.php';

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    echo basename($file) . "\n";
    require $file;
}

exit(run_tests());
```

- [ ] **Step 3: Verify the runner works on an empty test set**

Run: `php tests/run.php`
Expected: `0 passed, 0 failed` and exit 0.

Run: `echo $?`
Expected: `0`

- [ ] **Step 4: Commit**

```bash
git add tests/_helpers.php tests/run.php
git commit -m "Add minimal test runner"
```

---

## Task 3: Codes Module (TDD)

**Files:**
- Test: `tests/codes_test.php`
- Create: `src/codes.php`

This module has three pure functions: `normalize_code`, `format_code`, `generate_code`. The first two are deterministic and TDD-trivial. `generate_code` takes a "code exists" callback for testability.

- [ ] **Step 1: Write the failing tests**

Create `tests/codes_test.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: errors about `normalize_code` undefined (or all FAIL).

- [ ] **Step 3: Implement the module**

Create `src/codes.php`:

```php
<?php
declare(strict_types=1);

/**
 * Strip non-digits and require exactly 6 digits.
 * Returns the 6-digit string, or null if invalid.
 */
function normalize_code(string $input): ?string
{
    $digits = preg_replace('/\D+/', '', $input);
    return strlen($digits) === 6 ? $digits : null;
}

/**
 * Format a stored 6-digit code for display: 'XXX-XXX'.
 */
function format_code(string $code): string
{
    return substr($code, 0, 3) . '-' . substr($code, 3, 3);
}

/**
 * Generate a new 6-digit code. $exists($code) returns true if the code is taken.
 * Throws RuntimeException after 10 unsuccessful attempts.
 */
function generate_code(callable $exists): string
{
    for ($i = 0; $i < 10; $i++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!$exists($code)) {
            return $code;
        }
    }
    throw new RuntimeException('could not generate unique code after 10 attempts');
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: all 10 tests pass, exit 0.

- [ ] **Step 5: Commit**

```bash
git add src/codes.php tests/codes_test.php
git commit -m "Add codes module with normalize, format, generate"
```

---

## Task 4: Env Loader

**Files:**
- Create: `src/env.php`

Tiny `.env` parser. No tests — it's trivial and exercised by integration tests.

- [ ] **Step 1: Write the loader**

Create `src/env.php`:

```php
<?php
declare(strict_types=1);

/**
 * Load a .env file into $_ENV and getenv(). Lines are KEY=VALUE.
 * Supports quoted values, # comments, and blank lines.
 */
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        // Strip surrounding single or double quotes.
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $_ENV[$key] = $val;
        putenv("$key=$val");
    }
}

function env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? getenv($key);
    return $v === false || $v === null ? $default : (string) $v;
}
```

- [ ] **Step 2: Smoke test it**

Run:
```bash
cat > /tmp/test.env <<'EOF'
FOO=bar
QUOTED="hello world"
# a comment
EMPTY=
EOF
php -r 'require "src/env.php"; load_env("/tmp/test.env"); echo env("FOO") . "|" . env("QUOTED") . "|" . env("EMPTY", "def") . "\n";'
```
Expected: `bar|hello world|`

- [ ] **Step 3: Commit**

```bash
rm /tmp/test.env
git add src/env.php
git commit -m "Add tiny .env loader"
```

---

## Task 5: Database Module

**Files:**
- Create: `src/db.php`

- [ ] **Step 1: Write the module**

Create `src/db.php`:

```php
<?php
declare(strict_types=1);

/**
 * Get the PDO connection. Initializes the DB and runs the migration on first call.
 * $path defaults to data/links.db relative to the project root.
 */
function get_db(?string $path = null): PDO
{
    static $pdo = null;
    static $cachedPath = null;
    $path = $path ?? __DIR__ . '/../data/links.db';

    if ($pdo !== null && $cachedPath === $path) {
        return $pdo;
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS links (
            code       TEXT PRIMARY KEY,
            url        TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )
    SQL);

    $cachedPath = $path;
    return $pdo;
}
```

(Tests use a subprocess via `proc_open`, which gets a fresh static cache, so we don't need a reset helper.)

- [ ] **Step 2: Smoke test the migration**

Run:
```bash
rm -f /tmp/smoke.db
php -r 'require "src/db.php"; $db = get_db("/tmp/smoke.db"); $db->exec("INSERT INTO links VALUES (\"123456\", \"https://example.com\", 1)"); foreach ($db->query("SELECT * FROM links") as $r) print_r($r);'
```
Expected output includes:
```
[code] => 123456
[url] => https://example.com
[created_at] => 1
```

- [ ] **Step 3: Cleanup and commit**

```bash
rm /tmp/smoke.db
git add src/db.php
git commit -m "Add db module with PDO connection and migration"
```

---

## Task 6: Auth Module (TDD)

**Files:**
- Test: `tests/auth_test.php`
- Create: `src/auth.php`

We test the pure pieces (`verify_csrf`, `bearer_token_from_header`, `check_bearer`); session and `require_admin`/`require_bearer` (which call `header()`/`exit`) are exercised in the integration test.

- [ ] **Step 1: Write the failing tests**

Create `tests/auth_test.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: errors about undefined functions.

- [ ] **Step 3: Implement the module**

Create `src/auth.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: all codes + auth tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/auth.php tests/auth_test.php
git commit -m "Add auth module with session, CSRF, and bearer token helpers"
```

---

## Task 7: Front Controller and Dispatcher

**Files:**
- Create: `public/index.php`
- Create: `src/routes.php` (initially with a stub `dispatch()` and `view()` helper)

This task wires up routing without implementing the actual route handlers yet. After this, `php -S 127.0.0.1:8765 -t public public/index.php` should serve a working "hello" 200 from `/`, 404 from `/anything-else`.

- [ ] **Step 1: Write the front controller**

Create `public/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/env.php';
require __DIR__ . '/../src/codes.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/routes.php';

load_env(__DIR__ . '/../.env');

configure_session();
session_start();

dispatch();
```

- [ ] **Step 2: Write the dispatcher stub**

Create `src/routes.php`:

```php
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
```

- [ ] **Step 3: Add a minimal layout and notfound view**

Create `views/_layout.php`:

```php
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title ?? 'southside.cc') ?></title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, -apple-system, sans-serif; max-width: 32rem;
           margin: 4rem auto; padding: 0 1rem; line-height: 1.5; }
    h1 { font-size: 1.5rem; }
    input, button { font: inherit; padding: 0.5rem 0.75rem; border-radius: 0.375rem;
                    border: 1px solid #888; }
    input[type=text], input[type=url], input[type=password] { width: 100%; box-sizing: border-box; }
    button { cursor: pointer; }
    .row { display: flex; gap: 0.5rem; margin: 0.5rem 0; }
    .err { color: #c0392b; }
    table { border-collapse: collapse; width: 100%; }
    th, td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #ccc; }
    code { font-family: ui-monospace, monospace; }
    .muted { color: #888; font-size: 0.9rem; }
  </style>
</head>
<body>
  <?= $content ?>
</body>
</html>
```

Create `views/notfound.php`:

```php
<h1>Not found</h1>
<p>That code isn't in the directory. <a href="/">Try another</a>.</p>
```

- [ ] **Step 4: Smoke test**

Start the dev server in the background:
```bash
php -S 127.0.0.1:8765 -t public >/tmp/php.log 2>&1 &
SERVER_PID=$!
sleep 0.5
```

Hit it:
```bash
curl -s http://127.0.0.1:8765/
```
Expected: `shortener boot ok`

```bash
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8765/nope
```
Expected: `404`

Kill the server: `kill $SERVER_PID`

- [ ] **Step 5: Commit**

```bash
git add public/index.php src/routes.php views/_layout.php views/notfound.php
git commit -m "Add front controller, dispatcher stub, and base layout"
```

---

## Task 8: Public Flow — code entry and redirect

**Files:**
- Modify: `src/routes.php` (replace stub dispatcher and add handlers)
- Create: `views/enter.php`

- [ ] **Step 1: Replace the dispatcher with the public routing**

In `src/routes.php`, replace the body of `dispatch()` with:

```php
function dispatch(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

    if ($method === 'GET' && $path === '/') {
        return route_enter_form();
    }
    if ($method === 'POST' && $path === '/go') {
        return route_go();
    }
    if ($method === 'GET' && preg_match('#^/([0-9]{6}|[0-9]{3}-[0-9]{3})$#', $path, $m)) {
        return route_redirect($m[1]);
    }
    not_found();
}
```

- [ ] **Step 2: Add the public route handlers**

Append to `src/routes.php`:

```php
function route_enter_form(string $error = ''): void
{
    render('enter', ['title' => 'southside.cc', 'error' => $error]);
}

function route_go(): void
{
    $code = normalize_code((string) ($_POST['code'] ?? ''));
    if ($code === null) {
        return route_enter_form('Enter a 6-digit code.');
    }
    $stmt = get_db()->prepare('SELECT url FROM links WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) {
        return route_enter_form('That code isn\'t in the directory.');
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
```

- [ ] **Step 3: Create the entry view**

Create `views/enter.php`:

```php
<h1>southside.cc</h1>
<p>Enter the 6-digit code:</p>
<form method="post" action="/go" autocomplete="off">
  <div class="row">
    <input
      type="text"
      name="code"
      inputmode="numeric"
      pattern="[0-9]{3}-?[0-9]{3}"
      maxlength="7"
      placeholder="123-456"
      autofocus
      required>
    <button type="submit">Go</button>
  </div>
  <?php if (!empty($error)): ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
  <?php endif ?>
</form>
```

- [ ] **Step 4: Smoke test**

Start the dev server:
```bash
php -S 127.0.0.1:8765 -t public >/tmp/php.log 2>&1 &
SERVER_PID=$!
sleep 0.5
```

Insert a test row directly:
```bash
sqlite3 data/links.db "INSERT OR REPLACE INTO links VALUES ('456767', 'https://example.com', strftime('%s','now'))"
```

Test direct redirect (hyphen form):
```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.1:8765/456-767
```
Expected: `302 https://example.com`

Test direct redirect (no hyphen):
```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.1:8765/456767
```
Expected: `302 https://example.com`

Test form post:
```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' -X POST http://127.0.0.1:8765/go -d 'code=456-767'
```
Expected: `302 https://example.com`

Test the form HTML:
```bash
curl -s http://127.0.0.1:8765/ | grep -c 'name="code"'
```
Expected: `1`

Test unknown code:
```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8765/000-000
```
Expected: `404`

Kill the server: `kill $SERVER_PID`

- [ ] **Step 5: Commit**

```bash
rm -f data/links.db
git add src/routes.php views/enter.php
git commit -m "Add public code entry form and redirect handlers"
```

---

## Task 9: Admin Login Flow

**Files:**
- Modify: `src/routes.php` (extend dispatcher, add handlers)
- Create: `views/login.php`

- [ ] **Step 1: Extend the dispatcher**

In `src/routes.php`, in `dispatch()`, replace the final `not_found();` call with the admin block followed by `not_found()`:

```php
    // Admin auth
    if ($method === 'GET'  && $path === '/admin')         return route_admin_root();
    if ($method === 'POST' && $path === '/admin/login')   return route_admin_login();
    if ($method === 'POST' && $path === '/admin/logout')  return route_admin_logout();

    not_found();
}
```

- [ ] **Step 2: Add the admin auth handlers**

Append to `src/routes.php`:

```php
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
```

- [ ] **Step 3: Create the login view**

Create `views/login.php`:

```php
<h1>admin login</h1>
<form method="post" action="/admin/login" autocomplete="off">
  <div class="row">
    <input type="password" name="password" placeholder="password" autofocus required>
    <button type="submit">Sign in</button>
  </div>
  <?php if (!empty($error)): ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
  <?php endif ?>
</form>
```

- [ ] **Step 4: Smoke test**

Generate an admin password hash for testing:
```bash
HASH=$(php -r 'echo password_hash("test123", PASSWORD_BCRYPT);')
cat > .env <<EOF
ADMIN_PASSWORD_HASH='$HASH'
API_TOKEN=test_token_for_smoke_only
BASE_URL=http://127.0.0.1:8765
EOF
```

Start the server:
```bash
php -S 127.0.0.1:8765 -t public >/tmp/php.log 2>&1 &
SERVER_PID=$!
sleep 0.5
```

Wrong password rejected:
```bash
curl -s -o /dev/null -w '%{http_code}\n' -c /tmp/cj.txt -X POST http://127.0.0.1:8765/admin/login -d 'password=wrong'
```
Expected: `401`

Correct password redirects:
```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' -c /tmp/cj.txt -X POST http://127.0.0.1:8765/admin/login -d 'password=test123'
```
Expected: `302 http://127.0.0.1:8765/admin/links`

(`/admin/links` itself isn't implemented yet, so curling it will 404 — that's fine; auth is what we're testing.)

Logout:
```bash
curl -s -o /dev/null -w '%{http_code}\n' -b /tmp/cj.txt -X POST http://127.0.0.1:8765/admin/logout
```
Expected: `302`

Cleanup:
```bash
kill $SERVER_PID
rm /tmp/cj.txt .env
rm -f data/links.db
```

- [ ] **Step 5: Commit**

```bash
git add src/routes.php views/login.php
git commit -m "Add admin login and logout"
```

---

## Task 10: Admin Link Management

**Files:**
- Modify: `src/routes.php`
- Create: `views/admin.php`

- [ ] **Step 1: Extend the dispatcher**

In `src/routes.php`, in `dispatch()`, add these route checks immediately after the admin auth block, before `not_found()`:

```php
    // Admin link management (all require auth)
    if ($method === 'GET'  && $path === '/admin/links')   return route_admin_links_list();
    if ($method === 'POST' && $path === '/admin/links')   return route_admin_links_create();
    if ($method === 'POST' && preg_match('#^/admin/links/([0-9]{6})/delete$#', $path, $m)) {
        return route_admin_links_delete($m[1]);
    }
```

- [ ] **Step 2: Add the handlers**

Append to `src/routes.php`:

```php
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
        return route_admin_links_list('Invalid form submission.', '');
    }
    $url = trim((string) ($_POST['url'] ?? ''));
    $rawCode = trim((string) ($_POST['code'] ?? ''));

    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        return route_admin_links_list('URL must be a valid http(s) URL.', '');
    }

    $db = get_db();
    if ($rawCode !== '') {
        $code = normalize_code($rawCode);
        if ($code === null) {
            return route_admin_links_list('Custom code must be 6 digits (XXX-XXX).', '');
        }
        $stmt = $db->prepare('SELECT 1 FROM links WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn()) {
            return route_admin_links_list("Code " . format_code($code) . " is already taken.", '');
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
    return route_admin_links_list('', $code);
}

function route_admin_links_delete(string $code): void
{
    require_admin();
    if (!verify_csrf($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        return route_admin_links_list('Invalid form submission.', '');
    }
    $stmt = get_db()->prepare('DELETE FROM links WHERE code = ?');
    $stmt->execute([$code]);
    header('Location: /admin/links', true, 302);
}
```

- [ ] **Step 3: Create the admin view**

Create `views/admin.php`:

```php
<h1>admin</h1>
<p><a href="/">public site</a> · <form style="display:inline" method="post" action="/admin/logout"><button>log out</button></form></p>

<h2>create link</h2>
<form method="post" action="/admin/links">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <div class="row"><input type="url" name="url" placeholder="https://..." required></div>
  <div class="row">
    <input type="text" name="code" placeholder="optional custom code (XXX-XXX)" pattern="[0-9]{3}-?[0-9]{3}">
    <button type="submit">Create</button>
  </div>
  <?php if (!empty($error)): ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
  <?php endif ?>
  <?php if (!empty($created)): ?>
    <p>Created <code><?= htmlspecialchars(format_code($created)) ?></code> →
       <a href="/<?= htmlspecialchars(format_code($created)) ?>">/<?= htmlspecialchars(format_code($created)) ?></a></p>
  <?php endif ?>
</form>

<h2>links (<?= count($rows) ?>)</h2>
<?php if (empty($rows)): ?>
  <p class="muted">No links yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>code</th><th>url</th><th>created</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $f = format_code($r['code']); ?>
    <tr>
      <td><a href="/<?= htmlspecialchars($f) ?>"><code><?= htmlspecialchars($f) ?></code></a></td>
      <td style="word-break:break-all"><?= htmlspecialchars($r['url']) ?></td>
      <td class="muted"><?= htmlspecialchars(date('Y-m-d', (int) $r['created_at'])) ?></td>
      <td>
        <form method="post" action="/admin/links/<?= htmlspecialchars($r['code']) ?>/delete"
              onsubmit="return confirm('Delete <?= htmlspecialchars($f) ?>?')">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button>delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
```

- [ ] **Step 4: Smoke test**

```bash
HASH=$(php -r 'echo password_hash("test123", PASSWORD_BCRYPT);')
cat > .env <<EOF
ADMIN_PASSWORD_HASH='$HASH'
API_TOKEN=test_token
BASE_URL=http://127.0.0.1:8765
EOF
php -S 127.0.0.1:8765 -t public >/tmp/php.log 2>&1 &
SERVER_PID=$!
sleep 0.5

# Login
curl -s -c /tmp/cj.txt -X POST http://127.0.0.1:8765/admin/login -d 'password=test123' -o /dev/null

# Fetch list (extracts CSRF from the form)
LIST_HTML=$(curl -s -b /tmp/cj.txt http://127.0.0.1:8765/admin/links)
CSRF=$(echo "$LIST_HTML" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')
echo "csrf=$CSRF"

# Create with custom code
curl -s -b /tmp/cj.txt -X POST http://127.0.0.1:8765/admin/links \
  --data-urlencode "csrf=$CSRF" \
  --data-urlencode "url=https://example.com" \
  --data-urlencode "code=456-767" -o /dev/null

# Verify redirect works
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.1:8765/456-767
```
Expected last line: `302 https://example.com`

Cleanup:
```bash
kill $SERVER_PID
rm -f /tmp/cj.txt .env data/links.db
```

- [ ] **Step 5: Commit**

```bash
git add src/routes.php views/admin.php
git commit -m "Add admin link list, create, and delete"
```

---

## Task 11: API Endpoint

**Files:**
- Modify: `src/routes.php`

- [ ] **Step 1: Extend the dispatcher**

In `src/routes.php`, in `dispatch()`, add this route check immediately before the final `not_found()`:

```php
    // API
    if ($method === 'POST' && $path === '/api/links')     return route_api_create();
```

- [ ] **Step 2: Add the handler**

Append to `src/routes.php`:

```php
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
```

- [ ] **Step 3: Smoke test**

```bash
cat > .env <<'EOF'
ADMIN_PASSWORD_HASH=
API_TOKEN=test_token_abc
BASE_URL=http://127.0.0.1:8765
EOF
php -S 127.0.0.1:8765 -t public >/tmp/php.log 2>&1 &
SERVER_PID=$!
sleep 0.5

# Auto-generated
curl -s -X POST http://127.0.0.1:8765/api/links \
  -H 'Authorization: Bearer test_token_abc' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com"}'
echo

# Custom code
curl -s -X POST http://127.0.0.1:8765/api/links \
  -H 'Authorization: Bearer test_token_abc' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/2","code":"123-456"}'
echo

# Conflict
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8765/api/links \
  -H 'Authorization: Bearer test_token_abc' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/3","code":"123-456"}'

# Unauthorized
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8765/api/links \
  -H 'Authorization: Bearer wrong' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com"}'
```
Expected:
- First line: `{"code":"XXX-XXX","short_url":"http://127.0.0.1:8765/XXX-XXX"}`
- Second line: `{"code":"123-456","short_url":"http://127.0.0.1:8765/123-456"}`
- Third line: `409`
- Fourth line: `401`

Cleanup:
```bash
kill $SERVER_PID
rm -f .env data/links.db
```

- [ ] **Step 4: Commit**

```bash
git add src/routes.php
git commit -m "Add POST /api/links endpoint"
```

---

## Task 12: Integration Test

**Files:**
- Create: `tests/integration_test.php`

This test boots the PHP built-in server in a subprocess, hits real endpoints, and asserts. It takes the place of separate unit tests for views and full routing.

- [ ] **Step 1: Write the integration test**

Create `tests/integration_test.php`:

```php
<?php
declare(strict_types=1);

// Spin up the dev server with a temp .env and a temp DB, run requests, tear down.

it('integration: full happy path', function () {
    $root = dirname(__DIR__);
    $port = 18765;
    $base = "http://127.0.0.1:$port";

    // 1. Prepare a temp .env and DB.
    $tempDir = sys_get_temp_dir() . '/shortener-it-' . bin2hex(random_bytes(4));
    mkdir($tempDir, 0755, true);
    $envPath = $tempDir . '/.env';
    $dbPath  = $tempDir . '/links.db';

    $hash = password_hash('test123', PASSWORD_BCRYPT);
    file_put_contents($envPath, implode("\n", [
        "ADMIN_PASSWORD_HASH='$hash'",
        "API_TOKEN=integration_token",
        "BASE_URL=$base",
    ]));

    // index.php honors SHORTENER_ENV_PATH and SHORTENER_DB_PATH (see Task 12 Step 2).
    $envVars = [
        'SHORTENER_ENV_PATH' => $envPath,
        'SHORTENER_DB_PATH'  => $dbPath,
        'PATH'               => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $tempDir . '/server.log', 'a'],
        2 => ['file', $tempDir . '/server.log', 'a'],
    ];
    $cmd = ['php', '-S', "127.0.0.1:$port", '-t', $root . '/public'];
    $proc = proc_open($cmd, $descriptors, $pipes, $root, $envVars);
    if (!is_resource($proc)) {
        throw new RuntimeException('failed to start dev server');
    }

    // Wait for the server to accept connections.
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($fp) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(50_000);
    }
    if (!$ready) {
        proc_terminate($proc);
        throw new RuntimeException('dev server never came up; see ' . $tempDir . '/server.log');
    }

    $call = function (string $method, string $path, array $opts = []) use ($base) {
        $ch = curl_init($base . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if (!empty($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }
        if (!empty($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if (!empty($opts['cookie_jar'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['cookie_jar']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['cookie_jar']);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new RuntimeException('curl failed: ' . curl_error($ch));
        }
        $info = curl_getinfo($ch);
        $headerSize = $info['header_size'];
        $headers = substr($resp, 0, $headerSize);
        $body = substr($resp, $headerSize);
        curl_close($ch);
        $location = '';
        if (preg_match('/^Location:\s*(.+?)\r?$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }
        return ['status' => $info['http_code'], 'headers' => $headers, 'body' => $body, 'location' => $location];
    };

    $cookieJar = $tempDir . '/cookies.txt';

    try {
        // Public form renders
        $r = $call('GET', '/');
        assert_eq(200, $r['status'], 'GET /');
        assert_true(str_contains($r['body'], 'name="code"'), 'GET / has code field');

        // Unknown code 404s
        $r = $call('GET', '/000-000');
        assert_eq(404, $r['status'], 'GET /000-000 unknown');

        // API: unauthorized
        $r = $call('POST', '/api/links', [
            'headers' => ['Content-Type: application/json'],
            'body' => '{"url":"https://example.com"}',
        ]);
        assert_eq(401, $r['status'], 'POST /api/links no token');

        // API: create with custom code
        $r = $call('POST', '/api/links', [
            'headers' => ['Authorization: Bearer integration_token', 'Content-Type: application/json'],
            'body' => '{"url":"https://example.com","code":"456-767"}',
        ]);
        assert_eq(200, $r['status'], 'POST /api/links create');
        $json = json_decode($r['body'], true);
        assert_eq('456-767', $json['code'] ?? null);

        // Redirect by hyphenated code
        $r = $call('GET', '/456-767');
        assert_eq(302, $r['status']);
        assert_eq('https://example.com', $r['location']);

        // Redirect by unhyphenated code
        $r = $call('GET', '/456767');
        assert_eq(302, $r['status']);
        assert_eq('https://example.com', $r['location']);

        // API: conflict on duplicate custom code
        $r = $call('POST', '/api/links', [
            'headers' => ['Authorization: Bearer integration_token', 'Content-Type: application/json'],
            'body' => '{"url":"https://example.com/2","code":"456-767"}',
        ]);
        assert_eq(409, $r['status'], 'POST /api/links conflict');

        // Admin: redirected to login when not authed
        $r = $call('GET', '/admin/links', ['cookie_jar' => $cookieJar]);
        assert_eq(302, $r['status']);

        // Admin: login with bad password
        $r = $call('POST', '/admin/login', [
            'body' => 'password=wrong',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'cookie_jar' => $cookieJar,
        ]);
        assert_eq(401, $r['status']);

        // Admin: login with correct password
        $r = $call('POST', '/admin/login', [
            'body' => 'password=test123',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'cookie_jar' => $cookieJar,
        ]);
        assert_eq(302, $r['status']);

        // Admin: list now visible
        $r = $call('GET', '/admin/links', ['cookie_jar' => $cookieJar]);
        assert_eq(200, $r['status']);
        assert_true(str_contains($r['body'], '456-767'), 'admin list shows the link');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        @unlink($cookieJar);
        @unlink($envPath);
        @unlink($dbPath);
        @unlink($tempDir . '/server.log');
        @rmdir($tempDir);
    }
});
```

- [ ] **Step 2: Update `public/index.php` to honor temp env/db paths**

Replace `public/index.php` with this version:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/env.php';
require __DIR__ . '/../src/codes.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/routes.php';

$envPath = getenv('SHORTENER_ENV_PATH') ?: (__DIR__ . '/../.env');
load_env($envPath);

$dbPath = getenv('SHORTENER_DB_PATH') ?: (__DIR__ . '/../data/links.db');
get_db($dbPath); // initialize early so handlers don't have to pass the path

configure_session();
session_start();

dispatch();
```

- [ ] **Step 3: Run the full suite**

```bash
php tests/run.php
```
Expected: all unit tests pass, integration block runs without throwing, summary shows `N passed, 0 failed`.

If integration fails, check `/tmp/shortener-it-*/server.log` for stack traces.

- [ ] **Step 4: Commit**

```bash
git add tests/integration_test.php public/index.php
git commit -m "Add end-to-end integration test"
```

---

## Task 13: Deploy Guide

**Files:**
- Create: `DEPLOY.md`
- Modify: `data/.gitkeep` (no change needed; ensures dir is tracked)

- [ ] **Step 1: Write the deploy guide**

Create `DEPLOY.md`:

````markdown
# Deploying southside.cc shortener to Forge

## One-time setup

1. **Forge: create site**
   - Domain: `southside.cc`
   - Project type: General PHP / Static
   - Web Directory: `/public`
   - PHP Version: 8.2 or newer

2. **Provision HTTPS** via Forge's Let's Encrypt one-click.

3. **Deploy the code**: connect the Git repo and run `git pull`, or push via `forge deploy`. There is no build step.

4. **Generate secrets**

   On any machine with PHP:
   ```bash
   php -r 'echo password_hash("YOUR_PASSWORD_HERE", PASSWORD_BCRYPT) . "\n";'
   php -r 'echo bin2hex(random_bytes(24)) . "\n";'
   ```

5. **Create `.env` on the server** (project root, sibling of `public/`):

   ```
   ADMIN_PASSWORD_HASH='$2y$12$...'
   API_TOKEN='<the random hex from above>'
   BASE_URL='https://southside.cc'
   ```

   Permissions: `chown forge:forge .env && chmod 600 .env`.

6. **Ensure `data/` is writable**:
   ```bash
   chown -R forge:forge data && chmod 755 data
   ```

7. **First request** creates `data/links.db` automatically.

## Smoke test

After deploy:

```bash
# Should return the entry form HTML
curl -s https://southside.cc/ | grep 'name="code"'

# Create a link via API
curl -X POST https://southside.cc/api/links \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://anthropic.com"}'

# Should respond with {"code":"XXX-XXX","short_url":"https://southside.cc/XXX-XXX"}
```

## Backup

The entire app state is `data/links.db`. To back it up:

```bash
sqlite3 data/links.db ".backup /tmp/links-$(date +%F).db"
```

Schedule this nightly via Forge's scheduler (cron) if you care.

## Updating

```bash
cd /home/forge/southside.cc
git pull
```

No migrations — schema is idempotent and runs on every boot.
````

- [ ] **Step 2: Commit**

```bash
git add DEPLOY.md
git commit -m "Add deploy guide"
```

---

## Task 14: Final Verification

- [ ] **Step 1: Run the full test suite**

```bash
php tests/run.php
```
Expected: all tests pass.

- [ ] **Step 2: Manual end-to-end smoke**

```bash
HASH=$(php -r 'echo password_hash("admin", PASSWORD_BCRYPT);')
TOK=$(php -r 'echo bin2hex(random_bytes(24));')
cat > .env <<EOF
ADMIN_PASSWORD_HASH='$HASH'
API_TOKEN='$TOK'
BASE_URL=http://127.0.0.1:8000
EOF

php -S 127.0.0.1:8000 -t public &
SERVER_PID=$!
sleep 0.5

echo "Open http://127.0.0.1:8000/ in a browser."
echo "Admin: http://127.0.0.1:8000/admin (password: admin)"
echo "API token: $TOK"
echo "Press enter to stop the server..."
read

kill $SERVER_PID
rm -f .env data/links.db
```

Verify visually:
- The home page form accepts `123-456` and shows an inline error if unknown.
- Admin login works; bad password is rejected.
- Creating a link with both auto and custom codes works.
- Deleting a link removes it from the list.
- Visiting `/<code>` redirects to the URL.

- [ ] **Step 3: Confirm no leftover artifacts**

```bash
ls .env data/links.db data/test.db 2>&1
```
Expected: all "No such file or directory" — those are gitignored and shouldn't be present.

```bash
git status
```
Expected: working tree clean.

- [ ] **Step 4: Tag the initial release**

```bash
git tag -a v0.1.0 -m "Initial working release"
git log --oneline
```

Plan complete. The repo is ready to push to a remote and deploy via Forge per `DEPLOY.md`.
