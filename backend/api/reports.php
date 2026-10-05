<?php
/**
 * Reports: daily / weekly / monthly / yearly / custom date-range summaries
 * of sales, expenses, damages, and net profit.
 *
 * GET  ?period=daily|weekly|monthly|yearly|custom&from=&to=
 *      -> JSON summary + line-level breakdown for on-screen display.
 * GET  ?...&download=csv
 *      -> downloads the same data as a .csv file (opens in Excel).
 * GET  ?...&download=pdf
 *      -> returns a print-ready HTML page; the frontend opens it and
 *         calls window.print(), letting the browser "Save as PDF" —
 *         this avoids needing a heavyweight PDF library on the server.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    send_api_headers();
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$download = $_GET['download'] ?? '';
if ($download !== 'pdf') {
    // PDF mode renders a full HTML page itself; everything else is JSON/CSV.
    send_api_headers();
}

require_non_worker();

$period = $_GET['period'] ?? 'daily';
[$from, $to] = resolve_report_range($period, $_GET['from'] ?? null, $_GET['to'] ?? null);

// ---- Sales in range ----
$salesStmt = $pdo->prepare('SELECT * FROM sales WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date, created_at');
$salesStmt->execute([$from, $to]);
$sales = $salesStmt->fetchAll();

$totalSales = 0;
$totalItemsSold = 0;
$totalProfit = 0;
$unknownCostCount = 0;
$unknownCostRevenue = 0;
foreach ($sales as $s) {
    $totalSales += (float) $s['total_amount'];
    $totalItemsSold += (int) $s['quantity'];
    // unit_cost can be NULL (cost not provided, e.g. a service with no
    // buying_cost set) — that must NOT be treated as a zero cost, or
    // profit gets silently inflated. Track it separately instead.
    if ($s['unit_cost'] === null) {
        $unknownCostCount++;
        $unknownCostRevenue += (float) $s['total_amount'];
        continue;
    }
    $totalProfit += ((float) $s['unit_price'] - (float) $s['unit_cost']) * (int) $s['quantity'];
}

// ---- Expenses in range ----
$expStmt = $pdo->prepare('SELECT * FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date');
$expStmt->execute([$from, $to]);
$expenses = $expStmt->fetchAll();
$totalExpenses = array_sum(array_map(fn($e) => (float) $e['amount'], $expenses));

// ---- Damages in range ----
$dmgStmt = $pdo->prepare('SELECT * FROM damages WHERE damage_date BETWEEN ? AND ? ORDER BY damage_date');
$dmgStmt->execute([$from, $to]);
$damages = $dmgStmt->fetchAll();
$totalDamageLoss = array_sum(array_map(fn($d) => (float) $d['loss_amount'], $damages));

$netProfit = $totalProfit - $totalExpenses - $totalDamageLoss;

$summary = [
    'period' => $period,
    'from' => $from,
    'to' => $to,
    'total_sales' => round($totalSales, 2),
    'total_items_sold' => $totalItemsSold,
    'total_expenses' => round($totalExpenses, 2),
    'total_damage_loss' => round($totalDamageLoss, 2),
    'gross_profit' => round($totalProfit, 2),
    'net_profit' => round($netProfit, 2),
    // Sales whose cost is genuinely unknown (e.g. a service with no
    // buying_cost set) — excluded from gross/net profit above rather
    // than assumed to cost nothing.
    'unknown_cost_sale_count' => $unknownCostCount,
    'unknown_cost_revenue' => round($unknownCostRevenue, 2),
];

// ---------------------------------------------------------
// CSV download
// ---------------------------------------------------------
if ($download === 'csv') {
    $rows = [];
    $rows[] = ['SUMMARY'];
    $rows[] = ['Period', $period, 'From', $from, 'To', $to];
    $rows[] = ['Total Sales', $summary['total_sales']];
    $rows[] = ['Total Items Sold', $summary['total_items_sold']];
    $rows[] = ['Total Expenses', $summary['total_expenses']];
    $rows[] = ['Total Damage Loss', $summary['total_damage_loss']];
    $rows[] = ['Net Profit', $summary['net_profit']];
    $rows[] = [];
    $rows[] = ['SALES'];
    $rows[] = ['Date', 'Product', 'Qty', 'Unit Price', 'Total', 'Profit'];
    foreach ($sales as $s) {
        $rows[] = [$s['sale_date'], $s['product_name'], $s['quantity'], $s['unit_price'], $s['total_amount'], round(($s['unit_price'] - $s['unit_cost']) * $s['quantity'], 2)];
    }
    $rows[] = [];
    $rows[] = ['EXPENSES'];
    $rows[] = ['Date', 'Description', 'Category', 'Amount'];
    foreach ($expenses as $e) {
        $rows[] = [$e['expense_date'], $e['description'], $e['category'], $e['amount']];
    }
    $rows[] = [];
    $rows[] = ['DAMAGES'];
    $rows[] = ['Date', 'Product', 'Qty', 'Reason', 'Loss Amount'];
    foreach ($damages as $d) {
        $rows[] = [$d['damage_date'], $d['product_name'], $d['quantity'], $d['reason'], $d['loss_amount']];
    }
    output_csv("report_{$period}_{$from}_to_{$to}.csv", ['Report'], $rows);
}

// ---------------------------------------------------------
// Print-ready HTML for "Download PDF" (browser's Save-as-PDF)
// ---------------------------------------------------------
if ($download === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $money = fn($n) => number_format((float) $n, 2);
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Report {$from} to {$to}</title>";
    echo "<style>body{font-family:Arial,sans-serif;padding:24px;color:#222;}h1{font-size:20px;}table{width:100%;border-collapse:collapse;margin-bottom:24px;}th,td{border:1px solid #ccc;padding:6px 10px;font-size:13px;text-align:left;}th{background:#f0f0f0;}.summary td{font-weight:bold;}@media print{button{display:none;}}</style></head><body>";
    echo "<button onclick='window.print()'>Print / Save as PDF</button>";
    echo "<h1>INNO's Stationery — Report ({$period}: {$from} to {$to})</h1>";
    echo "<table class='summary'><tr><td>Total Sales</td><td>" . $money($summary['total_sales']) . "</td></tr>";
    echo "<tr><td>Total Items Sold</td><td>{$summary['total_items_sold']}</td></tr>";
    echo "<tr><td>Total Expenses</td><td>" . $money($summary['total_expenses']) . "</td></tr>";
    echo "<tr><td>Total Damage Loss</td><td>" . $money($summary['total_damage_loss']) . "</td></tr>";
    echo "<tr><td>Net Profit</td><td>" . $money($summary['net_profit']) . "</td></tr></table>";

    echo "<h2>Sales</h2><table><tr><th>Date</th><th>Product</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>";
    foreach ($sales as $s) {
        echo "<tr><td>{$s['sale_date']}</td><td>" . htmlspecialchars($s['product_name']) . "</td><td>{$s['quantity']}</td><td>" . $money($s['unit_price']) . "</td><td>" . $money($s['total_amount']) . "</td></tr>";
    }
    echo "</table>";

    echo "<h2>Expenses</h2><table><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th></tr>";
    foreach ($expenses as $e) {
        echo "<tr><td>{$e['expense_date']}</td><td>" . htmlspecialchars($e['description']) . "</td><td>" . htmlspecialchars($e['category'] ?? '') . "</td><td>" . $money($e['amount']) . "</td></tr>";
    }
    echo "</table>";

    echo "<h2>Damages</h2><table><tr><th>Date</th><th>Product</th><th>Qty</th><th>Reason</th><th>Loss</th></tr>";
    foreach ($damages as $d) {
        echo "<tr><td>{$d['damage_date']}</td><td>" . htmlspecialchars($d['product_name']) . "</td><td>{$d['quantity']}</td><td>{$d['reason']}</td><td>" . $money($d['loss_amount']) . "</td></tr>";
    }
    echo "</table></body></html>";
    exit;
}

// ---------------------------------------------------------
// Default: JSON for on-screen display
// ---------------------------------------------------------
json_response([
    'success' => true,
    'summary' => $summary,
    'sales' => $sales,
    'expenses' => $expenses,
    'damages' => $damages,
]);
