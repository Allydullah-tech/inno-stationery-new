<?php
/**
 * Public landing-page numbers for TODAY — totals only.
 * Returns: total sales amount, items sold, number of sales (receipts).
 * Never returns profit, costs, expenses, customers or any other private data.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$pdo = get_db();
$st = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(quantity),0) AS items,
                            COUNT(DISTINCT COALESCE(receipt_no, CONCAT("id", id))) AS receipts
                     FROM sales WHERE sale_date = ?');
$st->execute([date('Y-m-d')]);
$r = $st->fetch();

header('Cache-Control: public, max-age=60');
json_response([
    'success' => true,
    'date' => date('Y-m-d'),
    'total_sales' => (float) $r['total'],
    'items_sold' => (int) $r['items'],
    'sales_count' => (int) $r['receipts'],
]);
