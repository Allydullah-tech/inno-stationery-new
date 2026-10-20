<?php
/**
 * Customers — built from credit sales (name + phone). Shows what each
 * customer owes, how reliable they are, and lets an admin lift (or apply)
 * a credit restriction. Also tracks change that customers left with the
 * shop and lets staff record when it is handed back.
 *
 *  GET  ?q=&status=            list with totals
 *  GET  ?id=                   one customer with debts + change
 *  GET  ?view=change           change still owed to customers
 *  POST ?action=collect        {hold_id, amount, date}  hand change back
 *  POST ?action=lift|restrict  {id, reason}             (admins)
 *  PUT  {id, name}                                      (admins)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$admin = require_admin_auth();

/** Link older debts (made before customers existed) to customer rows. */
function backfill_customers(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, customer_name, customer_phone FROM debts WHERE customer_id IS NULL LIMIT 500')->fetchAll();
    foreach ($rows as $d) {
        $c = upsert_customer($pdo, $d['customer_name'], $d['customer_phone']);
        if ($c) {
            $pdo->prepare('UPDATE debts SET customer_id=? WHERE id=?')->execute([$c['id'], $d['id']]);
        }
    }
    $pdo->exec('UPDATE sales s JOIN debts d ON d.sale_id = s.id SET s.customer_id = d.customer_id WHERE s.customer_id IS NULL AND d.customer_id IS NOT NULL');
}

function change_open_rows(PDO $pdo, ?string $phone = null): array
{
    $sql = 'SELECT h.*, h.amount - COALESCE((SELECT SUM(amount) FROM change_collections WHERE hold_id=h.id),0) AS owed
            FROM change_holds h' . ($phone !== null ? ' WHERE h.customer_phone = ?' : '') . ' HAVING owed > 0.004 ORDER BY h.hold_date DESC, h.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($phone !== null ? [$phone] : []);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['amount'] = (float) $r['amount']; $r['owed'] = round((float) $r['owed'], 2); }
    return $rows;
}

function customer_stats_sql(): string
{
    return "SELECT c.*,
        COUNT(d.id) AS debt_count,
        COALESCE(SUM(d.original_amount),0) AS total_credit,
        COALESCE(SUM(d.amount_paid),0) AS total_paid,
        COALESCE(SUM(d.balance),0) AS total_balance,
        COALESCE(SUM(CASE WHEN d.balance > 0.004 THEN 1 ELSE 0 END),0) AS open_count,
        COALESCE(SUM(CASE WHEN d.balance > 0.004 AND d.payment_deadline < ? THEN 1 ELSE 0 END),0) AS overdue_count,
        COALESCE(SUM(CASE WHEN d.balance > 0.004 AND d.payment_deadline < ? THEN d.balance ELSE 0 END),0) AS overdue_amount,
        MAX(d.sale_date) AS last_credit_date
      FROM customers c LEFT JOIN debts d ON d.customer_id = c.id";
}

function customer_out(array $r): array
{
    foreach (['total_credit','total_paid','total_balance','overdue_amount'] as $k) { $r[$k] = round((float) $r[$k], 2); }
    foreach (['debt_count','open_count','overdue_count'] as $k) { $r[$k] = (int) $r[$k]; }
    $r['risk'] = $r['status'] === 'restricted' ? 'restricted'
        : ($r['overdue_count'] > 0 ? 'late' : ($r['total_balance'] > 0 ? 'owing' : 'good'));
    return $r;
}

if ($method === 'GET') {
    backfill_customers($pdo);
    $today = today_str();

    if (($_GET['view'] ?? '') === 'change') {
        $rows = change_open_rows($pdo);
        json_response(['success' => true, 'holds' => $rows, 'total_owed' => round(array_sum(array_column($rows, 'owed')), 2)]);
    }

    if (!empty($_GET['id'])) {
        $st = $pdo->prepare(customer_stats_sql() . ' WHERE c.id = ? GROUP BY c.id');
        $st->execute([$today, $today, (int) $_GET['id']]);
        $c = $st->fetch();
        if (!$c) {
            json_response(['success' => false, 'message' => 'Customer not found.'], 404);
        }
        $c = customer_out($c);
        $ds = $pdo->prepare('SELECT id, receipt_no, original_amount, amount_paid, balance, payment_deadline, sale_date, status FROM debts WHERE customer_id=? ORDER BY sale_date DESC, id DESC LIMIT 100');
        $ds->execute([$c['id']]);
        $debts = $ds->fetchAll();
        foreach ($debts as &$d) {
            $d['original_amount'] = (float) $d['original_amount']; $d['amount_paid'] = (float) $d['amount_paid']; $d['balance'] = (float) $d['balance'];
            $d['status'] = compute_debt_status($d['balance'], $d['amount_paid'], $d['payment_deadline']);
        }
        json_response(['success' => true, 'customer' => $c, 'debts' => $debts, 'change' => change_open_rows($pdo, $c['phone'])]);
    }

    $where = []; $params = [$today, $today];
    if (!empty($_GET['q'])) { $where[] = '(c.name LIKE ? OR c.phone LIKE ?)'; $q = '%' . $_GET['q'] . '%'; array_push($params, $q, $q); }
    if (($_GET['status'] ?? '') === 'restricted') { $where[] = "c.status = 'restricted'"; }
    $st = $pdo->prepare(customer_stats_sql() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY c.id ORDER BY (c.status=\'restricted\') DESC, total_balance DESC, c.name LIMIT 500');
    $st->execute($params);
    $rows = array_map('customer_out', $st->fetchAll());
    $riskFilter = $_GET['risk'] ?? '';
    if (in_array($riskFilter, ['good', 'owing', 'late', 'restricted'], true)) {
        $rows = array_values(array_filter($rows, fn($r) => $r['risk'] === $riskFilter));
    }
    $summary = ['customers' => count($rows), 'restricted' => 0, 'owing' => 0.0, 'overdue' => 0.0];
    foreach ($rows as $r) {
        if ($r['status'] === 'restricted') { $summary['restricted']++; }
        $summary['owing'] += $r['total_balance'];
        $summary['overdue'] += $r['overdue_amount'];
    }
    $summary['owing'] = round($summary['owing'], 2);
    $summary['overdue'] = round($summary['overdue'], 2);
    json_response(['success' => true, 'customers' => $rows, 'summary' => $summary]);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'collect') {
    $b = read_json_body();
    $holdId = (int) ($b['hold_id'] ?? 0);
    $amount = round((float) ($b['amount'] ?? 0), 2);
    $date = (string) ($b['date'] ?? today_str());
    assert_date_allowed($admin, $date);
    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'Enter the amount of change handed back.'], 400);
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT h.*, h.amount - COALESCE((SELECT SUM(amount) FROM change_collections WHERE hold_id=h.id),0) AS owed FROM change_holds h WHERE h.id=? FOR UPDATE');
        $st->execute([$holdId]);
        $h = $st->fetch();
        if (!$h) { throw new RuntimeException('Change record not found.'); }
        if ($amount > (float) $h['owed'] + 0.001) { throw new RuntimeException('Only ' . number_format((float) $h['owed']) . ' of change is still owed on this record.'); }
        $pdo->prepare('INSERT INTO change_collections (hold_id, amount, collected_date, recorded_by) VALUES (?,?,?,?)')->execute([$holdId, $amount, $date, $admin['id']]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()], 400);
    }
    log_activity($pdo, $admin, 'create', 'change_collection', $holdId, 'Change of ' . number_format($amount) . ' handed back to ' . ($h['customer_name'] ?: 'customer') . ' (receipt ' . ($h['receipt_no'] ?: '-') . ')');
    json_response(['success' => true, 'message' => 'Change handed back and recorded.'], 201);
}

if ($method === 'POST' && in_array($_GET['action'] ?? '', ['lift', 'restrict'], true)) {
    require_non_worker();
    $b = read_json_body();
    $id = (int) ($b['id'] ?? 0);
    $reason = trim((string) ($b['reason'] ?? ''));
    $st = $pdo->prepare('SELECT * FROM customers WHERE id=?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) {
        json_response(['success' => false, 'message' => 'Customer not found.'], 404);
    }
    if (mb_strlen($reason) > 200) {
        json_response(['success' => false, 'message' => 'Reason is too long.'], 400);
    }
    if ($_GET['action'] === 'lift') {
        $pdo->prepare("UPDATE customers SET status='active', lifted_by=?, lifted_at=NOW(), restriction_reason=NULL WHERE id=?")->execute([$admin['id'], $id]);
        log_activity($pdo, $admin, 'update', 'customer', $id, "Credit restriction lifted for {$c['name']} ({$c['phone']})" . ($reason ? ": {$reason}" : ''));
        json_response(['success' => true, 'message' => 'Restriction lifted. They can buy on credit again.']);
    }
    $pdo->prepare("UPDATE customers SET status='restricted', restricted_at=NOW(), restriction_reason=? WHERE id=?")->execute([$reason ?: 'Restricted by an admin.', $id]);
    log_activity($pdo, $admin, 'update', 'customer', $id, "Credit blocked for {$c['name']} ({$c['phone']})" . ($reason ? ": {$reason}" : ''));
    json_response(['success' => true, 'message' => 'Customer restricted from credit.']);
}

if ($method === 'PUT') {
    require_non_worker();
    $b = read_json_body();
    $name = trim((string) ($b['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        json_response(['success' => false, 'message' => 'Enter the customer name.'], 400);
    }
    $pdo->prepare('UPDATE customers SET name=? WHERE id=?')->execute([$name, (int) ($b['id'] ?? 0)]);
    log_activity($pdo, $admin, 'update', 'customer', $b['id'] ?? null, "Customer renamed to {$name}");
    json_response(['success' => true, 'message' => 'Customer updated.']);
}

json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
