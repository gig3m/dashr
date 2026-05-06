<?php
declare(strict_types=1);

/**
 * Get the PDO connection. Initializes the DB and runs the migration on first call.
 * $path defaults to data/links.db relative to the project root.
 * Once initialized, subsequent calls return the same cached instance regardless of $path.
 */
function get_db(?string $path = null): PDO
{
    static $pdo = null;
    static $initializedPath = null;

    // If not yet initialized, initialize now.
    if ($pdo === null) {
        $path = $path ?? __DIR__ . '/../data/links.db';
        $initializedPath = $path;

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
    }

    return $pdo;
}
