<?php
/** Streams a bank-slip photo/PDF to a logged-in user. ?id=<deposit id> */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin_auth();
$st = get_db()->prepare('SELECT receipt_file, receipt_mime FROM bank_deposits WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$r = $st->fetch();
$path = $r && $r['receipt_file'] ? BACKEND_ROOT . '/uploads/bank_receipts/' . basename($r['receipt_file']) : '';
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('Receipt not found.');
}
header('Content-Type: ' . ($r['receipt_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
readfile($path);
