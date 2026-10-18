<?php
// One shared database connection (PDO) for the whole project.
require_once __DIR__ . '/helpers.php';

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306')
         . ';dbname=' . env('DB_NAME', 'colm_registrar') . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        write_log('Database connection failed: ' . $e->getMessage());
        fail('Database connection failed. Check the DB_* values in your .env file.', 500);
    }
    ensure_schema_upgrades($pdo);
    return $pdo;
}

// An OLD database (installed before the newest version) gets the new tables/columns automatically the first time the site is used,
// so nobody is locked out when the files are replaced without running "php database/install.php" again. Safe to run many times.
// When everything is in place a small marker file is written and this does nothing any more.
function ensure_schema_upgrades($pdo) {
    $marker = __DIR__ . '/../storage/.schema_v2';
    if (is_file($marker)) return;
    try {
        $has = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn() > 0; };
        if (!$has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")) return;   // not installed yet
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, username VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL,
            KEY idx_login_ip (ip, created_at), KEY idx_login_user (ip, username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS upload_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_upload_attempts (user_id, created_at),
            CONSTRAINT fk_upload_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ($has("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'")) {
            if (!$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'reference_no'")) {
                $pdo->exec('ALTER TABLE payments ADD COLUMN reference_no VARCHAR(40) NULL AFTER method');
            }
            if (!$has("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'uq_payments_reference'")) {
                $pdo->exec('ALTER TABLE payments ADD UNIQUE KEY uq_payments_reference (reference_no)');
            }
        }
        @file_put_contents($marker, date('c'));
    } catch (Throwable $e) {
        write_log('Automatic database upgrade failed: ' . $e->getMessage() . ' (run: php database/install.php)');
    }
}

// Run a query with ? placeholders and return the statement.
function query($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_one($sql, $params = []) {
    $row = query($sql, $params)->fetch();
    return $row ?: null;
}

function fetch_all($sql, $params = []) {
    return query($sql, $params)->fetchAll();
}
