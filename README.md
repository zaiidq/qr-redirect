# Dynamic QR Code Service (self-hosted, PHP + SQLite)

Create QR codes that point to a fixed short URL on your server, e.g. `https://go.mydomain.com/yt`.
When someone scans it, the server redirects (HTTP **302**) to a target URL that you can change any time
from the admin page. The printed QR never changes — only the destination does.

- Plain PHP, no framework, no Composer, no build step.
- One SQLite file (`data/qr.db`) holds everything. It is created automatically.
- QR codes are drawn in the browser by the vendored MIT library `assets/qrcode.js`
  (`qrcode-generator` by Kazuhiko Arase). No CDN is used.

## Files

```
index.php        single entry point / router
config.php       default settings (tracked in git, keep it unchanged)
config.local.php YOUR real settings (create it; git-ignored, blocked from the web)
.htaccess        Apache routing + blocking of private files
web.config       IIS routing + blocking of private files
inc/             PHP helpers and HTML templates (blocked from the web)
data/            SQLite database (blocked from the web, must be writable)
assets/          admin.css, admin.js, qrcode.js
```

## 1. Requirements

- PHP **8.1 or newer** with the **`pdo_sqlite`** extension enabled
  (check with `php -m | grep -i sqlite`, or look for "pdo_sqlite" in `phpinfo()`).
- The app must be installed at the **root of a domain or subdomain** (e.g. `https://go.mydomain.com/`),
  not in a sub-folder.

## 2. Run locally

```bash
php -S localhost:8000 index.php
```

Then open <http://localhost:8000/admin>. For local testing create `config.local.php` (see below) with
`'BASE_URL' => 'http://localhost:8000'` and any test password.

## 3. Before deploying

Create `config.local.php` next to `config.php` with your real settings.
It overrides `config.php`, is ignored by git and is never served over HTTP,
so your password can't be committed by accident:

```php
<?php
return [
    'BASE_URL'       => 'https://go.mydomain.com',
    'ADMIN_PASSWORD' => 'a-long-strong-password',
];
```

Settings:

- `BASE_URL` → your real short domain, e.g. `https://go.mydomain.com` (no trailing slash).
  **This is the text printed inside every QR code.**
- `ADMIN_PASSWORD` → a long, strong password. While it is empty or `CHANGE_ME`, login is refused.
- `DB_PATH` → leave as is unless you want the database somewhere else.

## 4. Deploy

### Shared hosting / cPanel / Apache

1. Upload all files (including the hidden `.htaccess` files) to the document root of your (sub)domain.
2. Make sure the `data/` folder is writable by PHP (usually `755` or `775`; on some hosts `777`).
3. Open `https://go.mydomain.com/admin` and log in. Done.

`.htaccess` sends every request that is not a real file to `index.php`, and blocks `config.php`, `config.local.php`,
`data/` and `inc/`. Apache needs `mod_rewrite` and `AllowOverride All` (default on almost all shared hosts).

### nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name go.mydomain.com;
    root /var/www/qr;
    index index.php;

    # Never serve private files
    location ^~ /data/     { deny all; return 404; }
    location ^~ /inc/      { deny all; return 404; }
    location = /config.php { deny all; return 404; }
    location = /config.local.php { deny all; return 404; }
    location ~ /\.         { deny all; return 404; }   # .htaccess etc.

    location / {
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # adjust to your PHP-FPM socket
    }

    # Any other .php file is not part of the app
    location ~ \.php$ { return 404; }
}
```

Give the PHP-FPM user write access to `data/`:

```bash
sudo chown -R www-data:www-data /var/www/qr/data
```

### IIS (Windows)

1. Install PHP (FastCGI handler for `*.php`) and the **URL Rewrite** module.
2. Copy all files to the site folder. The included `web.config` handles routing and blocks
   `config.php`, `config.local.php`, `data/` and `inc/`.
3. Give the application pool identity (e.g. `IIS AppPool\YourSiteName`) **Modify** permission on the `data` folder.

### Tailscale Funnel (no domain needed)

If the server is on Tailscale, Funnel gives it a free public HTTPS address like
`https://my-server.tail1234.ts.net` that forwards to the app. No domain, no router port forwarding.

```bash
sudo tailscale funnel --bg 8082      # 8082 = the local port the app listens on
tailscale funnel status              # shows the public https://….ts.net URL
```

The first time, Tailscale prints a link to enable Funnel (and HTTPS certificates) for your tailnet.
Then set `BASE_URL` in `config.local.php` to the `https://….ts.net` address shown by `tailscale funnel status`.
Keep the machine name and tailnet name unchanged afterwards: they are part of every printed QR.

To stop: `sudo tailscale funnel --https=443 off`.

### HTTPS

Strongly recommended. Use Let's Encrypt (`certbot`) or the free SSL option in your hosting panel.
With HTTPS the login cookie is automatically marked `Secure`.

## 5. Backup

The whole state is the single file `data/qr.db` (plus your `config.local.php`). Copy it somewhere safe regularly.
To restore, put it back in `data/`.

## 6. Important warning

**The domain in `BASE_URL` must stay alive forever.** Every printed QR code contains
`BASE_URL/code`. If the domain expires or the server goes away, every printed QR stops working.
For the same reason, a code can never be renamed after creation — only its title and target URL can change.

## Notes

- `GET /health` returns `ok` (for uptime monitors).
- Codes: lowercase letters, digits and `-`, 1–50 chars. Reserved: `admin`, `login`, `logout`, `health`,
  `assets`, `data`, `inc`, `index.php`, `config.php`, `favicon.ico`, `robots.txt`.
  Codes are case-insensitive when scanned (`/YT` = `/yt`).
- Redirects are always **302** with `Cache-Control: no-store`, so changing the target takes effect immediately.
- Login is limited to 5 failed attempts per IP per 10 minutes. If you run behind a reverse proxy / CDN,
  all visitors may share one IP for this limit (the app deliberately ignores `X-Forwarded-For`,
  because it can be faked).
- Times shown on the admin page are in UTC.
