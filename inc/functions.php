<?php
// Helper functions. Not reachable over HTTP (inc/.htaccess, web.config, nginx rules).
declare(strict_types=1);

const RESERVED_CODES = [
    'admin', 'login', 'logout', 'health', 'assets', 'data', 'inc',
    'index.php', 'config.php', 'favicon.ico', 'robots.txt',
];
const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW    = 600; // seconds (10 minutes)

// ---------------------------------------------------------------- config

function load_config(): array
{
    $cfg = require __DIR__ . '/../config.php';
    $cfg['BASE_URL']       = rtrim(trim((string)($cfg['BASE_URL'] ?? '')), '/');
    $cfg['ADMIN_PASSWORD'] = (string)($cfg['ADMIN_PASSWORD'] ?? '');
    $cfg['DB_PATH']        = (string)($cfg['DB_PATH'] ?? (__DIR__ . '/../data/qr.db'));
    return $cfg;
}

function password_is_set(array $cfg): bool
{
    $pw = trim($cfg['ADMIN_PASSWORD']);
    return $pw !== '' && $pw !== 'CHANGE_ME';
}

// ---------------------------------------------------------------- database

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    global $config;
    $path = $config['DB_PATH'];
    $dir  = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create the data folder: $dir");
    }
    if (!is_writable($dir)) {
        throw new RuntimeException("The data folder is not writable by PHP: $dir");
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS links (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        code         TEXT NOT NULL UNIQUE,
        title        TEXT,
        target_url   TEXT NOT NULL,
        scans        INTEGER NOT NULL DEFAULT 0,
        last_scan_at TEXT,
        created_at   TEXT NOT NULL,
        updated_at   TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        ip           TEXT NOT NULL,
        attempted_at INTEGER NOT NULL
    )');
    return $pdo;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

// ---------------------------------------------------------------- output

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function no_cache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

// ---------------------------------------------------------------- session / auth / csrf

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') == 443) {
        return true;
    }
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('qrsess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['auth']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function client_ip(): string
{
    // Only REMOTE_ADDR: proxy headers can be forged to bypass the rate limit.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

// ---------------------------------------------------------------- validation

/** Returns an Arabic error message, or null when the code is valid. */
function code_error(string $code): ?string
{
    if ($code === '') {
        return 'الكود مطلوب';
    }
    if (in_array($code, RESERVED_CODES, true)) {
        return 'هذا الكود محجوز للنظام، اختر كوداً آخر';
    }
    if (!preg_match('/^[a-z0-9-]{1,50}$/', $code)) {
        return 'الكود غير صالح: استخدم حروفاً إنجليزية صغيرة وأرقاماً وشرطة (-) فقط، بحد أقصى 50 حرفاً';
    }
    return null;
}

/** Normalizes a target URL. Returns the clean URL or null if invalid. */
function clean_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000) {
        return null;
    }
    // Percent-encode non-ASCII characters (e.g. Arabic paths) so FILTER_VALIDATE_URL accepts them.
    $url = preg_replace_callback('/[^\x21-\x7E]+/', fn($m) => rawurlencode($m[0]), $url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }
    return $url;
}

function clean_title(string $title): string
{
    // Max 200 characters (UTF-8 safe, without requiring the mbstring extension).
    $title = trim($title);
    if (!preg_match('/^.{0,200}/us', $title, $m)) {
        return ''; // invalid UTF-8
    }
    return $m[0];
}
