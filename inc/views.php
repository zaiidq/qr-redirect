<?php
// HTML templates. Not reachable over HTTP.
declare(strict_types=1);

const APP_NAME = 'QR Redirect';

/** Small inline SVG icons (no external files, CSP-safe). */
function icon(string $name): string
{
    $paths = [
        'logo'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM14 19h2M19 14h2"/>',
        'copy'     => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/>',
        'logout'   => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/>',
        'scan'     => '<path d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4M4 12h16"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$name] ?? '') . '</svg>';
}

function page(string $title, string $body, bool $withJs = false): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    no_cache();
    $js = $withJs
        ? '<script src="/assets/qrcode.js?v=2"></script><script src="/assets/admin.js?v=2"></script>'
        : '';
    echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>' . e($title) . '</title>
<link rel="stylesheet" href="/assets/admin.css?v=2">
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

function brand(): string
{
    return '<span class="brand"><span class="brand-mark">' . icon('logo') . '</span>' . APP_NAME . '</span>';
}

function render_login(array $flashes, bool $passwordSet): void
{
    $body = '<main class="auth"><div class="auth-card">' . brand();
    if (!$passwordSet) {
        $body .= '<div class="flash err">The admin password is not set yet. Set <code>ADMIN_PASSWORD</code> in <code>config.local.php</code> (a strong password), then reload this page.</div>';
    } else {
        $body .= '<p class="muted">Sign in to manage your QR codes.</p>'
            . flash_html($flashes)
            . '<form method="post" action="/login" class="stack">'
            . csrf_field()
            . '<label class="field"><span>Password</span>'
            . '<input type="password" name="password" required autofocus autocomplete="current-password"></label>'
            . '<button type="submit" class="btn primary block">Sign in</button>'
            . '</form>';
    }
    $body .= '</div></main>';
    page('Sign in · ' . APP_NAME, $body);
}

function render_admin(array $config, array $links, array $flashes, array $old): void
{
    $base     = $config['BASE_URL'];
    $baseShow = preg_replace('#^https?://#i', '', $base) . '/';
    $total    = array_sum(array_map(fn($l) => (int)$l['scans'], $links));

    $body = '<header class="topbar"><div class="topbar-in">' . brand()
        . '<form method="post" action="/logout">' . csrf_field()
        . '<button type="submit" class="btn ghost">' . icon('logout') . '<span>Log out</span></button></form>'
        . '</div></header>'
        . '<main class="wrap">'
        . flash_html($flashes);

    // Create form
    $body .= '<section class="panel">'
        . '<div class="panel-head"><h2>New QR code</h2><p class="muted">The printed QR never changes. You can change where it points at any time.</p></div>'
        . '<form method="post" action="/admin" class="create">' . csrf_field()
        . '<input type="hidden" name="action" value="create">'
        . '<label class="field f-code"><span>Short code</span>'
        . '<span class="group"><span class="prefix">' . e($baseShow) . '</span>'
        . '<input name="code" required maxlength="50" pattern="[A-Za-z0-9\-]{1,50}" placeholder="menu" autocomplete="off" autocapitalize="off" spellcheck="false" value="' . e($old['code'] ?? '') . '"></span>'
        . '<small>Lowercase letters, numbers and dashes. Can\'t be changed later: it\'s printed inside the QR.</small></label>'
        . '<label class="field f-url"><span>Destination URL</span>'
        . '<input name="target_url" type="url" required placeholder="https://example.com/page" value="' . e($old['target_url'] ?? '') . '"></label>'
        . '<label class="field f-title"><span>Name <em>(optional)</em></span>'
        . '<input name="title" maxlength="200" placeholder="e.g. Restaurant menu" value="' . e($old['title'] ?? '') . '"></label>'
        . '<div class="f-submit"><button type="submit" class="btn primary">' . icon('plus') . '<span>Create QR code</span></button></div>'
        . '</form></section>';

    // List header
    $body .= '<section class="list-head"><div><h2>Your QR codes</h2>'
        . '<p class="muted">' . count($links) . ' ' . (count($links) === 1 ? 'code' : 'codes') . ' · ' . $total . ' total ' . ($total === 1 ? 'scan' : 'scans') . '</p></div>';
    if (count($links) > 3) {
        $body .= '<label class="search">' . icon('search') . '<input type="search" id="filter" placeholder="Search…" aria-label="Search QR codes"></label>';
    }
    $body .= '</section>';

    if (!$links) {
        $body .= '<div class="empty">' . icon('logo') . '<p><strong>No QR codes yet.</strong><br>Create your first one above.</p></div>';
    }

    $body .= '<div class="links">';
    foreach ($links as $l) {
        $short = $base . '/' . $l['code'];
        $id    = (int)$l['id'];
        $name  = ($l['title'] ?? '') !== '' ? $l['title'] : $l['code'];
        $last  = $l['last_scan_at']
            ? '<time datetime="' . e(str_replace(' ', 'T', $l['last_scan_at']) . 'Z') . '">' . e($l['last_scan_at']) . ' UTC</time>'
            : 'never';
        $search = strtolower($l['code'] . ' ' . ($l['title'] ?? '') . ' ' . $l['target_url']);

        $body .= '<article class="card" id="link-' . $id . '" data-url="' . e($short) . '" data-code="' . e($l['code']) . '" data-search="' . e($search) . '">'
            . '<div class="qr-box"><div class="qr" role="img" aria-label="QR code for ' . e($short) . '"></div></div>'
            . '<div class="card-body">'
            . '<div class="card-top"><h3>' . e($name) . '</h3>'
            . '<span class="chip" title="Total scans">' . icon('scan') . (int)$l['scans'] . '</span></div>'
            . '<div class="short"><a href="' . e($short) . '" target="_blank" rel="noopener" class="mono">' . e(preg_replace('#^https?://#i', '', $short)) . '</a>'
            . '<button type="button" class="btn icon-btn js-copy" data-copy="' . e($short) . '" title="Copy link" aria-label="Copy link">' . icon('copy') . '</button></div>'
            . '<p class="dest">' . icon('arrow') . '<a href="' . e($l['target_url']) . '" target="_blank" rel="noopener noreferrer">' . e($l['target_url']) . '</a></p>'
            . '<p class="meta">' . icon('clock') . 'Last scan: ' . $last . '</p>'
            . '<div class="actions">'
            . '<button type="button" class="btn js-png">' . icon('download') . '<span>PNG</span></button>'
            . '<button type="button" class="btn js-svg">' . icon('download') . '<span>SVG</span></button>'
            . '<button type="button" class="btn js-edit" aria-expanded="false" aria-controls="edit-' . $id . '">' . icon('edit') . '<span>Edit</span></button>'
            . '<form method="post" action="/admin" class="js-confirm del" data-confirm="Delete &quot;' . e($l['code']) . '&quot;? Any printed QR code for it will stop working.">' . csrf_field()
            . '<input type="hidden" name="action" value="delete">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="btn danger-ghost" title="Delete">' . icon('trash') . '<span>Delete</span></button></form>'
            . '</div>'
            . '<form method="post" action="/admin" class="stack edit-form" id="edit-' . $id . '" hidden>' . csrf_field()
            . '<input type="hidden" name="action" value="update">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<label class="field"><span>Destination URL</span><input name="target_url" type="url" required value="' . e($l['target_url']) . '"></label>'
            . '<label class="field"><span>Name</span><input name="title" maxlength="200" value="' . e($l['title']) . '"></label>'
            . '<div class="row"><button type="submit" class="btn primary">Save changes</button><button type="button" class="btn ghost js-cancel">Cancel</button></div>'
            . '</form>'
            . '</div></article>';
    }
    $body .= '</div><p class="no-match muted" hidden>No QR codes match your search.</p></main>';
    page(APP_NAME, $body, true);
}

function render_simple(int $status, string $title, string $html): void
{
    http_response_code($status);
    page($title, '<main class="auth"><div class="auth-card center">' . $html . '</div></main>');
}

function render_not_found(): void
{
    render_simple(404, 'Link not found',
        '<p class="big">404</p><h1>Link not found</h1><p class="muted">This QR code or link doesn\'t exist, or it was removed.</p>');
}
