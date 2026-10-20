<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$admin = require_admin_auth();
$pdo = get_db();

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

/** Totals for a single day (sales, items sold, expenses, damage loss, net profit). */
function dashboard_day_summary(PDO $pdo, string $day): array
{
    $st = $pdo->prepare('SELECT quantity, unit_price, unit_cost, total_amount FROM sales WHERE sale_date = ?');
    $st->execute([$day]);
    $sales = 0;
    $items = 0;
    $gross = 0;
    $count = 0;
    foreach ($st->fetchAll() as $s) {
        $sales += (float) $s['total_amount'];
        $items += (int) $s['quantity'];
        $gross += ((float) $s['unit_price'] - (float) $s['unit_cost']) * (int) $s['quantity'];
        $count++;
    }

    $ex = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = ?');
    $ex->execute([$day]);
    $expenses = (float) $ex->fetchColumn();

    $dm = $pdo->prepare('SELECT COALESCE(SUM(loss_amount),0) FROM damages WHERE damage_date = ?');
    $dm->execute([$day]);
    $damage = (float) $dm->fetchColumn();

    $sp = $pdo->prepare('SELECT COALESCE(SUM(total_cost),0) FROM service_purchases WHERE purchase_date = ?');
    $sp->execute([$day]);
    $serviceCost = (float) $sp->fetchColumn();

    return [
        'date' => $day,
        'service_materials_cost' => round($serviceCost, 2),
        'total_sales' => round($sales, 2),
        'total_items_sold' => $items,
        'sales_count' => $count,
        'total_expenses' => round($expenses, 2),
        'total_damage_loss' => round($damage, 2),
        'net_profit' => round($gross - $expenses - $damage - $serviceCost, 2),
    ];
}

$todaySummary = dashboard_day_summary($pdo, $today);
$yesterdaySummary = dashboard_day_summary($pdo, $yesterday);

// ---- Stock overview ----
$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn();
$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock <= low_stock_threshold AND status = "active"')->fetchColumn();
$outOfStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock = 0 AND status = "active"')->fetchColumn();

// ---- Recent activity ----
$recentSales = $pdo->query('SELECT product_name, quantity, total_amount, sale_date, created_at FROM sales ORDER BY created_at DESC LIMIT 5')->fetchAll();
$recentExpenses = $pdo->query('SELECT description, amount, expense_date, created_at FROM expenses ORDER BY created_at DESC LIMIT 5')->fetchAll();
$lowStockList = $pdo->query('SELECT CONCAT_WS(\' - \', name, NULLIF(variant,\'\')) AS name, stock, low_stock_threshold FROM products WHERE stock <= low_stock_threshold AND status = "active" ORDER BY stock ASC LIMIT 8')->fetchAll();

// ---- Credit / debts ----
$dueSoon = date('Y-m-d', strtotime('+3 days'));
$dbt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN balance > 0.004 THEN balance END),0) AS outstanding,
        COALESCE(SUM(CASE WHEN balance > 0.004 AND payment_deadline < ? THEN balance END),0) AS overdue_amount,
        COALESCE(SUM(CASE WHEN balance > 0.004 AND payment_deadline < ? THEN 1 END),0) AS overdue_count,
        COALESCE(SUM(CASE WHEN balance > 0.004 AND payment_deadline BETWEEN ? AND ? THEN 1 END),0) AS due_soon_count
    FROM debts");
$dbt->execute([$today, $today, $today, $dueSoon]);
$debtRow = $dbt->fetch();

// ---- Cash audit status for today ----
$auditToday = $pdo->prepare('SELECT id, variance, counted_cash FROM cash_audits WHERE audit_date = ? ORDER BY id DESC LIMIT 1');
$auditToday->execute([$today]);
$auditRow = $auditToday->fetch();
$lastAudit = $pdo->query('SELECT audit_date, variance FROM cash_audits ORDER BY audit_date DESC, id DESC LIMIT 1')->fetch();

$changeOwed = (float) $pdo->query('SELECT COALESCE(SUM(h.amount),0) - COALESCE((SELECT SUM(amount) FROM change_collections),0) FROM change_holds h')->fetchColumn();
$restricted = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'restricted'")->fetchColumn();
$serviceLow = $pdo->query('SELECT name, description, stock_quantity FROM service_items WHERE stock_quantity <= 5 ORDER BY stock_quantity ASC LIMIT 6')->fetchAll();

json_response([
    'success' => true,
    'debts' => [
        'outstanding' => round((float) $debtRow['outstanding'], 2),
        'overdue_amount' => round((float) $debtRow['overdue_amount'], 2),
        'overdue_count' => (int) $debtRow['overdue_count'],
        'due_soon_count' => (int) $debtRow['due_soon_count'],
    ],
    'cash_audit' => [
        'done_today' => (bool) $auditRow,
        'today_variance' => $auditRow ? (float) $auditRow['variance'] : null,
        'last_date' => $lastAudit['audit_date'] ?? null,
        'last_variance' => isset($lastAudit['variance']) ? (float) $lastAudit['variance'] : null,
    ],
    'change_owed' => round($changeOwed, 2),
    'restricted_customers' => $restricted,
    'service_low_stock' => $serviceLow,
    'today' => $todaySummary,
    'yesterday' => $yesterdaySummary,
    'stock' => [
        'total_products' => $totalProducts,
        'low_stock_count' => $lowStockCount,
        'out_of_stock_count' => $outOfStockCount,
        'low_stock_list' => $lowStockList,
    ],
    'recent_sales' => $recentSales,
    'recent_expenses' => $recentExpenses,
]);
