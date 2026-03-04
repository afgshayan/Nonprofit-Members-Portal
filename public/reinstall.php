<?php
/**
 * Nonprofit Members Portal — Reinstall Helper
 * -----------------------------------------------
 * ⚠ SECURITY: This script is DISABLED by default.
 *   To enable, remove or comment out the die() line below.
 *   DELETE this file from the server after use.
 *
 * Usage (CLI):  php public/reinstall.php
 * Usage (Web):  visit http://your-site/reinstall.php  (then delete it)
 *
 * What it does:
 *   1. Removes storage/installed.lock  → unlocks the web installer
 *   2. Resets .env from .env.example   → clears all credentials
 *   3. Drops all DB tables             → clean slate for migrations
 *   4. Clears Laravel caches           → no stale config/session
 */

// ── SECURITY GUARD ── remove this line to enable ─────────────────────────────
die('Reinstall script is disabled. Remove the die() line in this file to enable.');

define('ROOT', __DIR__ . '/..');
$isCli = php_sapi_name() === 'cli';

function out(string $msg, bool $ok = true): void {
    global $isCli;
    if ($isCli) {
        echo ($ok ? "  [OK]  " : " [FAIL] ") . $msg . PHP_EOL;
    } else {
        $color = $ok ? '#16a34a' : '#dc2626';
        echo "<p style='font-family:monospace;color:{$color}'>" . ($ok ? '✔' : '✘') . " {$msg}</p>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
    <title>Reinstall – Nonprofit Members Portal</title>
    <style>body{font-family:sans-serif;max-width:640px;margin:60px auto;padding:0 20px}
    h2{color:#1e3a5f}hr{border:1px solid #e5e7eb}</style></head><body>
    <h2>⚙ Reinstall – Nonprofit Members Portal</h2><hr>";
}

$errors = 0;

// ── 0. Read current DB credentials BEFORE resetting .env ─────────────────────
$envFile  = ROOT . '/.env';
$envExmp  = ROOT . '/.env.example';
$rawEnv   = file_exists($envFile) ? file_get_contents($envFile) : '';
preg_match('/^DB_HOST=(.+)$/m',     $rawEnv, $mH);
preg_match('/^DB_PORT=(.+)$/m',     $rawEnv, $mP);
preg_match('/^DB_DATABASE=(.+)$/m', $rawEnv, $mD);
preg_match('/^DB_USERNAME=(.+)$/m', $rawEnv, $mU);
preg_match('/^DB_PASSWORD=(.*)$/m', $rawEnv, $mW);
$dbHost = trim($mH[1] ?? '');
$dbPort = trim($mP[1] ?? '3306');
$dbName = trim($mD[1] ?? '');
$dbUser = trim($mU[1] ?? '');
$dbPass = trim($mW[1] ?? '');

// ── 1. Remove installed.lock ─────────────────────────────────────────────────
$lock = ROOT . '/storage/installed.lock';
if (file_exists($lock)) {
    if (unlink($lock)) {
        out('storage/installed.lock removed');
    } else {
        out('Could not remove storage/installed.lock (check permissions)', false);
        $errors++;
    }
} else {
    out('storage/installed.lock not found (already clean)');
}

// ── 2. Reset .env from .env.example ──────────────────────────────────────────
if (file_exists($envExmp)) {
    if (copy($envExmp, $envFile)) {
        out('.env reset from .env.example');
    } else {
        out('Could not overwrite .env (check permissions)', false);
        $errors++;
    }
} else {
    out('.env.example not found — .env not reset', false);
    $errors++;
}

// ── 3. Drop all tables (using credentials captured before .env reset) ────────
if (
    !empty($dbHost) &&
    !empty($dbName) &&
    $dbName !== 'your_database_name' &&
    !empty($dbUser) &&
    $dbUser !== 'your_database_user'
) {
    try {
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        out('All database tables dropped (' . count($tables) . ' tables)');
    } catch (\Throwable $e) {
        out('Could not drop tables: ' . $e->getMessage(), false);
        $errors++;
    }
} else {
    out('DB credentials are placeholders — skipping table drop (will be done by installer)');
}

// ── 4. Clear Laravel caches ──────────────────────────────────────────────────
$cacheDirs = [
    ROOT . '/bootstrap/cache',
    ROOT . '/storage/framework/cache/data',
    ROOT . '/storage/framework/sessions',
    ROOT . '/storage/framework/views',
];
$cleared = 0;
foreach ($cacheDirs as $dir) {
    if (!is_dir($dir)) continue;
    foreach (glob($dir . '/*') as $file) {
        if (is_file($file) && basename($file) !== '.gitignore') {
            @unlink($file);
            $cleared++;
        }
    }
}
out("Laravel caches cleared ({$cleared} files removed)");

// ── Done ─────────────────────────────────────────────────────────────────────
if ($isCli) {
    echo PHP_EOL;
    if ($errors === 0) {
        echo "  ✔ Ready for fresh install. Visit /install/ in your browser." . PHP_EOL;
    } else {
        echo "  ⚠ Done with {$errors} error(s). Review messages above." . PHP_EOL;
    }
    echo PHP_EOL;
} else {
    echo "<hr>";
    if ($errors === 0) {
        echo "<p style='font-size:1.1em;color:#16a34a;font-weight:600'>
              ✔ Ready for fresh install.<br>
              <a href='../install/'>Go to Web Installer →</a></p>";
    } else {
        echo "<p style='color:#dc2626;font-weight:600'>⚠ Done with {$errors} error(s).</p>";
    }
    echo "<p style='color:#6b7280;font-size:.85em'>Delete this file after use for security.</p>";
    echo "</body></html>";
}
