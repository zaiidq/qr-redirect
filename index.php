<?php
// Single entry point / router for the dynamic QR service.
declare(strict_types=1);

// PHP built-in server (php -S localhost:8000 index.php): serve real files under /assets/ directly.
if (php_sapi_name() === 'cli-server') {
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($p, '/assets/')) {
        $file   = realpath(__DIR__ . rawurldecode($p));
        $assets = realpath(__DIR__ . '/assets');
        if ($file && $assets && str_starts_with($file, $assets . DIRECTORY_SEPARATOR) && is_file($file)) {
            return false;
        }
    }
}

require __DIR__ . '/inc/functions.php';
require __DIR__ . '/inc/views.php';

$config = load_config();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
if (!empty($_SERVER['HTTP_X_ORIGINAL_URL']) && stripos((string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'IIS') !== false) {
    $uri = $_SERVER['HTTP_X_ORIGINAL_URL']; // IIS URL Rewrite keeps the original URL here
}
$path   = strtolower(trim(rawurldecode((string)(parse_url($uri, PHP_URL_PATH) ?? '')), '/'));

try {
    switch ($path) {
        case '':
            redirect('/admin');

        case 'health':
            header('Content-Type: text/plain; charset=UTF-8');
            no_cache();
            echo 'ok';
            exit;

        case 'login':
            handle_login($config, $method);
            exit;

        case 'logout':
            if ($method !== 'POST') {
                header('Allow: POST');
                render_simple(405, 'Method not allowed', '<p>استخدم زر الخروج من لوحة التحكم.</p><p><a href="/admin">العودة</a></p>');
                exit;
            }
            start_session();
            if (csrf_ok()) {
                $_SESSION = [];
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'],
                    'httponly' => true, 'samesite' => 'Strict',
                ]);
                session_destroy();
            }
            redirect('/login');

        case 'admin':
            handle_admin($config, $method);
            exit;

        default:
            handle_code($path);
            exit;
    }
} catch (Throwable $ex) {
    error_log('[qr] ' . $ex->getMessage());
    $hint = $ex instanceof RuntimeException ? '<p dir="ltr" class="muted">' . e($ex->getMessage()) . '</p>' : '';
    render_simple(500, 'Error', '<h1>حدث خطأ في الخادم</h1><p dir="ltr">Server error</p>' . $hint);
    exit;
}

// ---------------------------------------------------------------- handlers

function handle_code(string $code): void
{
    // Only valid, non-reserved codes are looked up.
    if (code_error($code) !== null) {
        render_not_found();
        return;
    }
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT id, target_url FROM links WHERE code = ?');
    $stmt->execute([$code]);
    $link = $stmt->fetch();
    if (!$link) {
        render_not_found();
        return;
    }
    $pdo->prepare('UPDATE links SET scans = scans + 1, last_scan_at = ? WHERE id = ?')
        ->execute([now_utc(), $link['id']]);

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ' . $link['target_url'], true, 302); // never 301: browsers cache it
    exit;
}

function handle_login(array $config, string $method): void
{
    start_session();
    $passwordSet = password_is_set($config);

    if ($passwordSet && is_logged_in()) {
        redirect('/admin');
    }
    if ($method !== 'POST' || !$passwordSet) {
        render_login(take_flash(), $passwordSet);
        return;
    }

    if (!csrf_ok()) {
        flash('error', 'انتهت صلاحية الصفحة، حاول مرة أخرى.');
        redirect('/login');
    }

    $pdo = db();
    $ip  = client_ip();
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - LOGIN_WINDOW]);

    $count = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $count->execute([$ip]);
    $fails = (int)$count->fetchColumn();

    $blockedMsg = 'محاولات دخول خاطئة كثيرة. انتظر 10 دقائق ثم حاول مرة أخرى.';
    if ($fails >= LOGIN_MAX_FAILS) {
        http_response_code(429);
        render_login([['type' => 'error', 'msg' => $blockedMsg]], true);
        return;
    }

    $given = $_POST['password'] ?? '';
    $given = is_string($given) ? $given : '';
    if (hash_equals(hash('sha256', $config['ADMIN_PASSWORD']), hash('sha256', $given))) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        redirect('/admin');
    }

    $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([$ip, time()]);
    $fails++;
    if ($fails >= LOGIN_MAX_FAILS) {
        http_response_code(429);
        render_login([['type' => 'error', 'msg' => $blockedMsg]], true);
        return;
    }
    http_response_code(401);
    render_login([['type' => 'error', 'msg' => 'كلمة المرور غير صحيحة.']], true);
}

function handle_admin(array $config, string $method): void
{
    start_session();
    if (!password_is_set($config) || !is_logged_in()) {
        redirect('/login');
    }
    $pdo = db();

    if ($method === 'POST') {
        if (!csrf_ok()) {
            render_simple(403, 'Forbidden', '<h1>طلب مرفوض</h1><p>رمز الحماية (CSRF) غير صالح أو انتهت صلاحية الصفحة.</p><p><a href="/admin">العودة إلى لوحة التحكم</a></p>');
            return;
        }
        $action = (string)($_POST['action'] ?? '');
        $anchor = '';

        if ($action === 'create') {
            $code  = strtolower(trim((string)($_POST['code'] ?? '')));
            $title = clean_title((string)($_POST['title'] ?? ''));
            $rawUrl = (string)($_POST['target_url'] ?? '');
            $url   = clean_url($rawUrl);

            $err = code_error($code);
            if ($err === null && $url === null) {
                $err = 'الرابط غير صالح. يجب أن يبدأ بـ http:// أو https://';
            }
            if ($err === null) {
                $chk = $pdo->prepare('SELECT 1 FROM links WHERE code = ?');
                $chk->execute([$code]);
                if ($chk->fetchColumn()) {
                    $err = 'الكود مستخدم مسبقاً';
                }
            }
            if ($err === null) {
                try {
                    $now = now_utc();
                    $pdo->prepare('INSERT INTO links (code, title, target_url, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$code, $title, $url, $now, $now]);
                    $anchor = '#link-' . $pdo->lastInsertId();
                    flash('success', 'تم إنشاء الرابط ' . $config['BASE_URL'] . '/' . $code);
                } catch (PDOException $ex) {
                    $err = 'الكود مستخدم مسبقاً';
                }
            }
            if ($err !== null) {
                flash('error', $err);
                $_SESSION['old'] = [
                    'code' => (string)($_POST['code'] ?? ''),
                    'title' => (string)($_POST['title'] ?? ''),
                    'target_url' => $rawUrl,
                ];
            }
        } elseif ($action === 'update') {
            $id    = (int)($_POST['id'] ?? 0);
            $title = clean_title((string)($_POST['title'] ?? ''));
            $url   = clean_url((string)($_POST['target_url'] ?? ''));
            if ($url === null) {
                flash('error', 'الرابط غير صالح. يجب أن يبدأ بـ http:// أو https://');
            } else {
                $st = $pdo->prepare('UPDATE links SET title = ?, target_url = ?, updated_at = ? WHERE id = ?');
                $st->execute([$title, $url, now_utc(), $id]);
                flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'تم حفظ التعديلات' : 'الرابط غير موجود');
            }
            $anchor = '#link-' . $id;
        } elseif ($action === 'delete') {
            $st = $pdo->prepare('DELETE FROM links WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'تم حذف الرابط' : 'الرابط غير موجود');
        } else {
            flash('error', 'إجراء غير معروف');
        }
        redirect('/admin' . $anchor);
    }

    $links = $pdo->query('SELECT * FROM links ORDER BY id DESC')->fetchAll();
    $old = $_SESSION['old'] ?? [];
    unset($_SESSION['old']);
    render_admin($config, $links, take_flash(), $old);
}
