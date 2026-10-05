<?php
/**
 * INNO's STATIONERY (Internal System) - Site configuration
 */

// ---- Error display (turn off in production) ----
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---- Session ----
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- CORS (same-origin by default) ----
define('ALLOWED_ORIGIN', '*');

// ---- Paths ----
define('BACKEND_ROOT', dirname(__DIR__));
define('UPLOAD_DIR_PRODUCTS', BACKEND_ROOT . '/uploads/products/');

// Work out the public URL of the "backend" folder from the actual request,
// so uploaded product image URLs work whether the site is at the domain
// root or inside a subfolder.
if (!empty($_SERVER['SCRIPT_NAME'])) {
    $backendUrlBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
} else {
    $backendUrlBase = '/backend';
}
define('UPLOAD_URL_PRODUCTS', $backendUrlBase . '/uploads/products/');

// ---- Upload limits ----
define('MAX_UPLOAD_BYTES', 20 * 1024 * 1024); // 20MB
define('ALLOWED_IMAGE_EXT', ['jpg', 'jpeg', 'png', 'webp']);
define('ALLOWED_IMPORT_EXT', ['csv']);

// ---- Brand ----
define('SITE_BRAND', "INNO's STATIONERY — Internal System");
