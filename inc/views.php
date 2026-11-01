<?php
// HTML templates. Not reachable over HTTP.
declare(strict_types=1);

function page(string $title, string $body, bool $withJs = false): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    no_cache();
    $js = $withJs
        ? '<script src="/assets/qrcode.js"></script><script src="/assets/admin.js"></script>'
        : '';
    echo '<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . e($title) . '</title>
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
' . $body . '
' . $js . '
</body>
</html>';
}

function flash_html(array $flashes): string
{
    $out = '';
    foreach ($flashes as $f) {
        $cls = $f['type'] === 'success' ? 'flash ok' : 'flash err';
        $out .= '<div class="' . $cls . '" role="alert">' . e($f['msg']) . '</div>';
    }
    return $out;
}

function render_login(array $flashes, bool $passwordSet): void
{
    $body = '<main class="login-box"><h1>مولّد QR الديناميكي</h1>';
    if (!$passwordSet) {
        $body .= '<div class="flash err">لم يتم تعيين كلمة مرور المدير بعد. افتح الملف <code>config.php</code> وغيّر قيمة <code>ADMIN_PASSWORD</code> إلى كلمة مرور قوية، ثم أعد تحميل هذه الصفحة.</div>';
        $body .= '<p class="muted" dir="ltr">Admin password is not set. Edit <code>config.php</code> and change <code>ADMIN_PASSWORD</code>.</p>';
    } else {
        $body .= flash_html($flashes);
        $body .= '<form method="post" action="/login" class="card">'
            . csrf_field()
            . '<label for="password">كلمة المرور</label>'
            . '<input type="password" id="password" name="password" required autofocus autocomplete="current-password">'
            . '<button type="submit" class="btn primary">دخول</button>'
            . '</form>';
    }
    $body .= '</main>';
    page('تسجيل الدخول', $body);
}

function render_admin(array $config, array $links, array $flashes, array $old): void
{
    $base = $config['BASE_URL'];

    $body = '<header class="top"><h1>مولّد QR الديناميكي</h1>'
        . '<form method="post" action="/logout">' . csrf_field()
        . '<button type="submit" class="btn">خروج</button></form></header>'
        . '<main class="wrap">'
        . flash_html($flashes);

    // Create form
    $body .= '<section class="card"><h2>إنشاء رابط جديد</h2>'
        . '<form method="post" action="/admin" class="grid">' . csrf_field()
        . '<input type="hidden" name="action" value="create">'
        . '<div><label for="c-code">الكود</label>'
        . '<input id="c-code" name="code" required maxlength="50" dir="ltr" pattern="[A-Za-z0-9\-]{1,50}" placeholder="yt" value="' . e($old['code'] ?? '') . '">'
        . '<small class="muted">لا يمكن تغيير الكود بعد الإنشاء لأنه مطبوع داخل الـ QR. (حروف إنجليزية صغيرة، أرقام، شرطة -)</small></div>'
        . '<div><label for="c-title">العنوان (اختياري)</label>'
        . '<input id="c-title" name="title" maxlength="200" value="' . e($old['title'] ?? '') . '"></div>'
        . '<div><label for="c-url">الرابط الهدف</label>'
        . '<input id="c-url" name="target_url" type="url" required dir="ltr" placeholder="https://..." value="' . e($old['target_url'] ?? '') . '"></div>'
        . '<div><button type="submit" class="btn primary">إنشاء</button></div>'
        . '</form></section>';

    $body .= '<h2 class="list-title">الروابط (' . count($links) . ')</h2>';
    if (!$links) {
        $body .= '<p class="muted">لا توجد روابط بعد.</p>';
    }

    foreach ($links as $l) {
        $short = $base . '/' . $l['code'];
        $id    = (int)$l['id'];
        $title = $l['title'] !== null && $l['title'] !== '' ? $l['title'] : $l['code'];
        $last  = $l['last_scan_at'] ? $l['last_scan_at'] . ' UTC' : 'لم يُمسح بعد';

        $body .= '<article class="card link" id="link-' . $id . '" data-url="' . e($short) . '" data-code="' . e($l['code']) . '">'
            . '<div class="qr" aria-label="QR"></div>'
            . '<div class="info">'
            . '<h3>' . e($title) . '</h3>'
            . '<p class="short"><code dir="ltr">' . e($short) . '</code> '
            . '<button type="button" class="btn small js-copy" data-copy="' . e($short) . '">نسخ</button></p>'
            . '<p>الوجهة الحالية: <a href="' . e($l['target_url']) . '" target="_blank" rel="noopener noreferrer" dir="ltr" class="target">' . e($l['target_url']) . '</a></p>'
            . '<p class="muted">عدد المسحات: <strong class="scans">' . (int)$l['scans'] . '</strong> · آخر مسح: <span dir="ltr">' . e($last) . '</span></p>'
            . '<form method="post" action="/admin" class="grid edit">' . csrf_field()
            . '<input type="hidden" name="action" value="update">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<div><label for="t-' . $id . '">العنوان</label><input id="t-' . $id . '" name="title" maxlength="200" value="' . e($l['title']) . '"></div>'
            . '<div><label for="u-' . $id . '">الرابط الهدف</label><input id="u-' . $id . '" name="target_url" type="url" required dir="ltr" value="' . e($l['target_url']) . '"></div>'
            . '<div><button type="submit" class="btn primary">حفظ</button></div>'
            . '</form>'
            . '<div class="actions">'
            . '<button type="button" class="btn js-png">تحميل PNG</button>'
            . '<button type="button" class="btn js-svg">تحميل SVG</button>'
            . '<form method="post" action="/admin" class="js-confirm" data-confirm="هل أنت متأكد من حذف هذا الرابط؟ أي QR مطبوع له سيتوقف عن العمل.">' . csrf_field()
            . '<input type="hidden" name="action" value="delete">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="btn danger">حذف</button></form>'
            . '</div>'
            . '</div></article>';
    }
    $body .= '</main>';
    page('مولّد QR الديناميكي', $body, true);
}

function render_simple(int $status, string $title, string $html): void
{
    http_response_code($status);
    page($title, '<main class="login-box"><div class="card center">' . $html . '</div></main>');
}

function render_not_found(): void
{
    render_simple(404, 'Link not found',
        '<h1>الرابط غير موجود</h1><p dir="ltr">Link not found</p>');
}
