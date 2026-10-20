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

// ---- Service materials bought in range (service sales carry no cost of their own) ----
$spStmt = $pdo->prepare('SELECT COALESCE(SUM(total_cost),0) FROM service_purchases WHERE purchase_date BETWEEN ? AND ?');
$spStmt->execute([$from, $to]);
$serviceMaterialsCost = (float) $spStmt->fetchColumn();

// ---- Top sellers + expense breakdown + credit position ----
$topStmt = $pdo->prepare('SELECT product_name, SUM(quantity) AS qty, SUM(total_amount) AS revenue, SUM(CASE WHEN unit_cost IS NULL THEN 0 ELSE (unit_price - unit_cost) * quantity END) AS profit
    FROM sales WHERE sale_date BETWEEN ? AND ? GROUP BY product_name ORDER BY qty DESC LIMIT 10');
$topStmt->execute([$from, $to]);
$topSelling = $topStmt->fetchAll();
$expBreak = [];
foreach ($expenses as $e) {
    $k = trim((string) $e['category']) !== '' ? $e['category'] : 'Uncategorised';
    $expBreak[$k] = ($expBreak[$k] ?? 0) + (float) $e['amount'];
}
arsort($expBreak);
$creditStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_type='credit' AND sale_date BETWEEN ? AND ?");
$creditStmt->execute([$from, $to]);
$creditSales = (float) $creditStmt->fetchColumn();
$colStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM debt_payments WHERE payment_date BETWEEN ? AND ?');
$colStmt->execute([$from, $to]);
$debtsCollected = (float) $colStmt->fetchColumn();

$netProfit = $totalProfit - $totalExpenses - $totalDamageLoss - $serviceMaterialsCost;

$summary = [
    'period' => $period,
    'from' => $from,
    'to' => $to,
    'total_sales' => round($totalSales, 2),
    'total_items_sold' => $totalItemsSold,
    'total_expenses' => round($totalExpenses, 2),
    'total_damage_loss' => round($totalDamageLoss, 2),
    'gross_profit' => round($totalProfit, 2),
    'service_materials_cost' => round($serviceMaterialsCost, 2),
    'credit_sales' => round($creditSales, 2),
    'debts_collected' => round($debtsCollected, 2),
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
    $rows[] = ['Service Materials Bought', $summary['service_materials_cost']];
    $rows[] = ['Credit Sales', $summary['credit_sales']];
    $rows[] = ['Debts Collected', $summary['debts_collected']];
    $rows[] = ['Net Profit', $summary['net_profit']];
    $rows[] = [];
    $rows[] = ['SALES'];
    $rows[] = ['Date', 'Product', 'Qty', 'Unit Price', 'Total', 'Profit'];
    foreach ($sales as $s) {
        $rows[] = [$s['sale_date'], csv_safe($s['product_name']), $s['quantity'], $s['unit_price'], $s['total_amount'], round(($s['unit_price'] - $s['unit_cost']) * $s['quantity'], 2)];
    }
    $rows[] = [];
    $rows[] = ['EXPENSES'];
    $rows[] = ['Date', 'Description', 'Category', 'Amount'];
    foreach ($expenses as $e) {
        $rows[] = [$e['expense_date'], csv_safe($e['description']), csv_safe($e['category']), $e['amount']];
    }
    $rows[] = [];
    $rows[] = ['DAMAGES'];
    $rows[] = ['Date', 'Product', 'Qty', 'Reason', 'Loss Amount'];
    foreach ($damages as $d) {
        $rows[] = [$d['damage_date'], csv_safe($d['product_name']), $d['quantity'], $d['reason'], $d['loss_amount']];
    }
    output_csv("report_{$period}_{$from}_to_{$to}.csv", ['Report'], $rows);
}

// ---------------------------------------------------------
// Print-ready HTML for "Download PDF" (browser's Save-as-PDF)
// ---------------------------------------------------------
if ($download === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $money = fn($n) => number_format((float) $n, 0);
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $periodLabel = ucfirst($period);
    $rangeLabel = $from === $to ? date('j F Y', strtotime($from)) : date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
    $generated = date('j F Y, H:i');
    $profitClass = $summary['net_profit'] < 0 ? 'neg' : 'pos';

    $kpi = function (string $label, string $value, string $cls = '') {
        return "<div class='kpi {$cls}'><div class='k-label'>{$label}</div><div class='k-value'>{$value}</div></div>";
    };

    $emptyRow = fn(int $cols, string $msg) => "<tr class='empty'><td colspan='{$cols}'>{$msg}</td></tr>";

    echo "<!DOCTYPE html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo "<title>INNO's Stationery — Report {$h($from)} to {$h($to)}</title>";
    echo "<style>
    @page { size: A4; margin: 14mm 12mm 16mm; }
    * { box-sizing: border-box; }
    :root { --green:#1F5A41; --green2:#2F7D5C; --gold:#F0B429; --orange:#E85D2C; --ink:#1d2320; --soft:#6b7168; --line:#e6e2d4; --tint:#f6f3ea; --red:#C23B3B; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin:0; font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif; color:var(--ink); background:#ecebe4; font-size:12.5px; line-height:1.45; }
    .toolbar { position:sticky; top:0; z-index:5; display:flex; justify-content:center; gap:10px; padding:12px; background:#fff; border-bottom:1px solid var(--line); }
    .toolbar button { font:600 13px 'Segoe UI',Arial,sans-serif; border:0; border-radius:8px; padding:10px 18px; cursor:pointer; background:var(--green); color:#fff; }
    .toolbar button.alt { background:#fff; color:var(--ink); border:1.5px solid var(--line); }
    .sheet { max-width:210mm; margin:18px auto; background:#fff; box-shadow:0 10px 40px rgba(0,0,0,.12); border-radius:6px; overflow:hidden; }
    .hero { position:relative; padding:26px 30px 24px; color:#fff; background:linear-gradient(135deg, var(--green) 0%, #0f4a33 70%); overflow:hidden; }
    .hero::after { content:''; position:absolute; right:-60px; top:-70px; width:240px; height:240px; border-radius:50%; background:radial-gradient(circle, rgba(240,180,41,.45), rgba(240,180,41,0) 70%); }
    .brand { display:flex; align-items:center; gap:12px; position:relative; z-index:1; }
    .mark { width:40px; height:40px; border-radius:11px; background:var(--gold); color:#1d2320; font:800 20px 'Segoe UI',Arial,sans-serif; display:flex; align-items:center; justify-content:center; }
    .brand-name { font-weight:800; font-size:17px; letter-spacing:.01em; }
    .brand-sub { font-size:10.5px; letter-spacing:.14em; text-transform:uppercase; opacity:.75; }
    .hero h1 { position:relative; z-index:1; margin:20px 0 4px; font-size:26px; font-weight:800; letter-spacing:-.01em; }
    .hero .range { position:relative; z-index:1; display:flex; gap:10px; align-items:center; flex-wrap:wrap; font-size:13px; opacity:.95; }
    .pill { background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.28); border-radius:999px; padding:3px 12px; font-weight:600; font-size:11.5px; }
    .body { padding:22px 30px 10px; }
    .section-title { display:flex; align-items:center; gap:10px; margin:24px 0 10px; font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.1em; color:var(--green); break-after:avoid; }
    .section-title::before { content:''; width:4px; height:16px; border-radius:2px; background:var(--orange); }
    .section-title .count { margin-left:auto; font-size:11px; font-weight:600; letter-spacing:0; text-transform:none; color:var(--soft); background:var(--tint); border-radius:999px; padding:2px 10px; }
    .kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; }
    .kpi { position:relative; border:1px solid var(--line); border-radius:12px; padding:12px 14px 12px 16px; background:#fff; break-inside:avoid; overflow:hidden; }
    .kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--green2); }
    .kpi.gold::before { background:var(--gold); } .kpi.orange::before { background:var(--orange); } .kpi.red::before { background:var(--red); }
    .k-label { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--soft); }
    .k-value { margin-top:4px; font-size:16px; font-weight:800; font-variant-numeric:tabular-nums; }
    .k-value small { font-size:10px; font-weight:600; color:var(--soft); margin-right:2px; }
    .profit { margin-top:10px; display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-radius:12px; color:#fff; background:linear-gradient(135deg, var(--green2), var(--green)); break-inside:avoid; }
    .profit.neg { background:linear-gradient(135deg, #d05252, #a82e2e); }
    .profit .p-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; opacity:.85; }
    .profit .p-note { font-size:10.5px; opacity:.75; margin-top:2px; }
    .profit .p-value { font-size:24px; font-weight:800; font-variant-numeric:tabular-nums; }
    .two { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    table { width:100%; border-collapse:separate; border-spacing:0; font-size:12px; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
    thead { display:table-header-group; }
    th { background:var(--green); color:#fff; text-align:left; padding:8px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; }
    td { padding:7px 10px; border-top:1px solid var(--line); vertical-align:top; }
    tbody tr:nth-child(even) td { background:#faf8f1; }
    tr { break-inside:avoid; }
    .r { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
    tfoot td { background:var(--tint) !important; font-weight:800; border-top:2px solid var(--green2); }
    tr.empty td { text-align:center; color:var(--soft); padding:16px; background:#fff !important; font-style:italic; }
    .tag { display:inline-block; border-radius:999px; padding:1px 9px; font-size:10px; font-weight:700; background:#e3f1ea; color:var(--green2); }
    .tag.svc { background:#fdf0d5; color:#9a6b00; } .tag.red { background:#fbe7e5; color:var(--red); }
    .neg { color:var(--red); }
    .note { margin-top:14px; padding:10px 14px; border-radius:10px; background:#fff4d6; border:1px solid #f3d98b; color:#7a5600; font-size:11px; }
    .foot { display:flex; justify-content:space-between; gap:10px; margin-top:26px; padding:14px 30px 18px; border-top:1px solid var(--line); color:var(--soft); font-size:10.5px; background:var(--tint); }
    @media (max-width:700px) { .kpis { grid-template-columns:repeat(2,1fr); } .two { grid-template-columns:1fr; } .body { padding:18px 16px; } .hero { padding:22px 18px; } }
    @media print {
        body { background:#fff; } .toolbar { display:none; }
        .sheet { margin:0; max-width:none; box-shadow:none; border-radius:0; }
        .section-title { break-after:avoid; }
    }
    </style></head><body>";

    echo "<div class='toolbar'><button onclick='window.print()'>Print / Save as PDF</button><button class='alt' onclick='window.close()'>Close</button></div>";
    echo "<div class='sheet'>";

    // ---- Header ----
    echo "<div class='hero'><div class='brand'><div class='mark'>I</div><div><div class='brand-name'>INNO's Stationery</div><div class='brand-sub'>Internal System</div></div></div>";
    echo "<h1>{$h($periodLabel)} Business Report</h1>";
    echo "<div class='range'><span class='pill'>{$h($rangeLabel)}</span><span>Generated {$h($generated)}</span></div></div>";

    echo "<div class='body'>";

    // ---- Summary ----
    echo "<div class='section-title'>Summary</div><div class='kpis'>";
    echo $kpi('Total sales', "<small>TSh</small>" . $money($summary['total_sales']));
    echo $kpi('Items sold', (string) (int) $summary['total_items_sold'], 'gold');
    echo $kpi('Expenses', "<small>TSh</small>" . $money($summary['total_expenses']), 'orange');
    echo $kpi('Damage loss', "<small>TSh</small>" . $money($summary['total_damage_loss']), 'red');
    echo $kpi('Service materials', "<small>TSh</small>" . $money($summary['service_materials_cost']), 'orange');
    echo $kpi('Credit sales', "<small>TSh</small>" . $money($summary['credit_sales']), 'gold');
    echo $kpi('Debts collected', "<small>TSh</small>" . $money($summary['debts_collected']));
    echo $kpi('Gross profit', "<small>TSh</small>" . $money($summary['gross_profit']));
    echo "</div>";
    echo "<div class='profit {$profitClass}'><div><div class='p-label'>Net profit</div><div class='p-note'>Gross profit − expenses − damage loss − service materials</div></div><div class='p-value'>TSh " . $money($summary['net_profit']) . "</div></div>";
    if ($unknownCostCount > 0) {
        echo "<div class='note'>{$unknownCostCount} sale(s) totalling TSh " . $money($unknownCostRevenue) . " have no known cost and are excluded from the profit figures.</div>";
    }

    // ---- Top sellers + expense breakdown ----
    echo "<div class='two'><div><div class='section-title'>Top selling items</div><table><thead><tr><th>Item</th><th class='r'>Qty</th><th class='r'>Revenue</th></tr></thead><tbody>";
    if ($topSelling) {
        foreach ($topSelling as $t) {
            echo "<tr><td>{$h($t['product_name'])}</td><td class='r'>" . (int) $t['qty'] . "</td><td class='r'>" . $money($t['revenue']) . "</td></tr>";
        }
    } else {
        echo $emptyRow(3, 'No sales in this period');
    }
    echo "</tbody></table></div><div><div class='section-title'>Where the expenses went</div><table><thead><tr><th>Category</th><th class='r'>Amount</th></tr></thead><tbody>";
    if ($expBreak) {
        foreach ($expBreak as $k => $v) {
            echo "<tr><td>{$h($k)}</td><td class='r'>" . $money($v) . "</td></tr>";
        }
    } else {
        echo $emptyRow(2, 'No expenses in this period');
    }
    echo "</tbody></table></div></div>";

    // ---- Sales ----
    echo "<div class='section-title'>Sales<span class='count'>" . count($sales) . " record(s)</span></div>";
    echo "<table><thead><tr><th>Date</th><th>Item</th><th>Type</th><th class='r'>Qty</th><th class='r'>Unit price</th><th class='r'>Total</th></tr></thead><tbody>";
    if ($sales) {
        foreach ($sales as $s) {
            $isSvc = ($s['item_type'] ?? 'product') === 'service';
            echo "<tr><td>{$h($s['sale_date'])}</td><td>{$h($s['product_name'])}</td><td><span class='tag" . ($isSvc ? ' svc' : '') . "'>" . ($isSvc ? 'Service' : 'Product') . "</span></td><td class='r'>" . (int) $s['quantity'] . "</td><td class='r'>" . $money($s['unit_price']) . "</td><td class='r'>" . $money($s['total_amount']) . "</td></tr>";
        }
    } else {
        echo $emptyRow(6, 'No sales in this period');
    }
    echo "</tbody>" . ($sales ? "<tfoot><tr><td colspan='5'>Total sales</td><td class='r'>" . $money($summary['total_sales']) . "</td></tr></tfoot>" : '') . "</table>";

    // ---- Expenses ----
    echo "<div class='section-title'>Expenses<span class='count'>" . count($expenses) . " record(s)</span></div>";
    echo "<table><thead><tr><th>Date</th><th>Description</th><th>Category</th><th class='r'>Amount</th></tr></thead><tbody>";
    if ($expenses) {
        foreach ($expenses as $e) {
            echo "<tr><td>{$h($e['expense_date'])}</td><td>{$h($e['description'])}</td><td>{$h($e['category'] ?? '')}</td><td class='r'>" . $money($e['amount']) . "</td></tr>";
        }
    } else {
        echo $emptyRow(4, 'No expenses in this period');
    }
    echo "</tbody>" . ($expenses ? "<tfoot><tr><td colspan='3'>Total expenses</td><td class='r'>" . $money($summary['total_expenses']) . "</td></tr></tfoot>" : '') . "</table>";

    // ---- Damages ----
    echo "<div class='section-title'>Damages<span class='count'>" . count($damages) . " record(s)</span></div>";
    echo "<table><thead><tr><th>Date</th><th>Item</th><th class='r'>Qty</th><th>Reason</th><th class='r'>Loss</th></tr></thead><tbody>";
    if ($damages) {
        foreach ($damages as $d) {
            echo "<tr><td>{$h($d['damage_date'])}</td><td>{$h($d['product_name'])}</td><td class='r'>" . (int) $d['quantity'] . "</td><td><span class='tag red'>" . $h(ucfirst((string) $d['reason'])) . "</span></td><td class='r'>" . $money($d['loss_amount']) . "</td></tr>";
        }
    } else {
        echo $emptyRow(5, 'No damages in this period');
    }
    echo "</tbody>" . ($damages ? "<tfoot><tr><td colspan='4'>Total damage loss</td><td class='r'>" . $money($summary['total_damage_loss']) . "</td></tr></tfoot>" : '') . "</table>";

    echo "</div>"; // .body
    echo "<div class='foot'><span>INNO's Stationery — Internal System</span><span>Report period: {$h($from)} to {$h($to)}</span><span>Confidential</span></div>";
    echo "</div></body></html>";
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
    'top_selling' => $topSelling,
    'expense_breakdown' => array_map(fn($k, $v) => ['category' => $k, 'amount' => round($v, 2)], array_keys($expBreak), array_values($expBreak)),
]);
