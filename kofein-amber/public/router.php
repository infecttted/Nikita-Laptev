<?php
// Serve only known public assets; configuration and source remain outside the document root.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/assets/app.css', '/assets/app.js'], true)) return false;
require __DIR__ . '/index.php';
