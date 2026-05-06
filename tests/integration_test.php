<?php
declare(strict_types=1);

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
