<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$admin = require_admin_auth();
$pdo = get_db();

$today = date('Y-m-d');

// ---- Today's summary ----
$salesToday = $pdo->prepare('SELECT quantity, unit_price, unit_cost, total_amount FROM sales WHERE sale_date = ?');
$salesToday->execute([$today]);
$salesRows = $salesToday->fetchAll();

$todaySales = 0;
$todayItemsSold = 0;
$todayGrossProfit = 0;
foreach ($salesRows as $s) {
    $todaySales += (float) $s['total_amount'];
    $todayItemsSold += (int) $s['quantity'];
    $todayGrossProfit += ((float) $s['unit_price'] - (float) $s['unit_cost']) * (int) $s['quantity'];
}

$expToday = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = ?');
$expToday->execute([$today]);
$todayExpenses = (float) $expToday->fetchColumn();

$dmgToday = $pdo->prepare('SELECT COALESCE(SUM(loss_amount),0) FROM damages WHERE damage_date = ?');
$dmgToday->execute([$today]);
$todayDamageLoss = (float) $dmgToday->fetchColumn();

$todayNetProfit = $todayGrossProfit - $todayExpenses - $todayDamageLoss;

// ---- Stock overview ----
$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn();
$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock <= low_stock_threshold AND status = "active"')->fetchColumn();
$outOfStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock = 0 AND status = "active"')->fetchColumn();

// ---- Recent activity ----
$recentSales = $pdo->query('SELECT product_name, quantity, total_amount, sale_date, created_at FROM sales ORDER BY created_at DESC LIMIT 5')->fetchAll();
$recentExpenses = $pdo->query('SELECT description, amount, expense_date, created_at FROM expenses ORDER BY created_at DESC LIMIT 5')->fetchAll();
$lowStockList = $pdo->query('SELECT name, stock, low_stock_threshold FROM products WHERE stock <= low_stock_threshold AND status = "active" ORDER BY stock ASC LIMIT 8')->fetchAll();

json_response([
    'success' => true,
    'today' => [
        'date' => $today,
        'total_sales' => round($todaySales, 2),
        'total_items_sold' => $todayItemsSold,
        'total_expenses' => round($todayExpenses, 2),
        'total_damage_loss' => round($todayDamageLoss, 2),
        'net_profit' => round($todayNetProfit, 2),
    ],
    'stock' => [
        'total_products' => $totalProducts,
        'low_stock_count' => $lowStockCount,
        'out_of_stock_count' => $outOfStockCount,
        'low_stock_list' => $lowStockList,
    ],
    'recent_sales' => $recentSales,
    'recent_expenses' => $recentExpenses,
]);
