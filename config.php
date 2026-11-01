<?php
// Settings for the dynamic QR service.
// This file must never be served over HTTP (blocked by .htaccess / web.config / nginx rules).
return [
    'BASE_URL'       => 'https://go.mydomain.com', // what gets encoded in the QR; no trailing slash
    'ADMIN_PASSWORD' => 'CHANGE_ME',
    'DB_PATH'        => __DIR__ . '/data/qr.db',
];
