<?php
/**
 * Cash audit — end-of-day count of the drawer and mobile-money balance,
 * compared with what the system says should be there.
 *
 *  GET  ?action=preview&date=   live figures + opening cash + any saved audit
 *  GET  ?id=                    one saved audit
 *  GET  ?from=&to=&limit=       history (newest first)
 *  GET  ?action=export&from=&to=   CSV
 *  POST                         save the day's audit (one per date)
 *  PUT                          correct a saved audit        (admins only)
 *  DELETE ?id=                  remove a saved audit         (admins only)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cash_helper.php';

send_api_headers();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$admin = require_admin_auth();

function audit_out(array $r): array
{
    foreach (['opening_cash','cash_sales','online_sales','credit_sales','debt_cash','debt_online','expenses_cash','expenses_online','change_held','change_paid','expected_cash','expected_online','counted_cash','counted_online','cash_variance','online_variance','variance','bank_deposit','remaining_cash'] as $k) {
        if (isset($r[$k])) {
            $r[$k] = (float) $r[$k];
        }
    }
    $r['denominations'] = $r['denominations'] ? (json_decode($r['denominations'], true) ?: []) : [];
    $r['status'] = abs($r['variance']) < 0.5 ? 'balanced' : ($r['variance'] > 0 ? 'over' : 'short');
    return $r;
}

function audit_fetch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT a.*, u.full_name AS recorded_by_name FROM cash_audits a LEFT JOIN admins u ON u.id=a.recorded_by WHERE a.id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? audit_out($r) : null;
}

/** Build the values to store for $date from the posted count. Returns [row] or exits with an error. */
function audit_build(PDO $pdo, string $date, array $body): array
{
    [$counted, $clean] = ch_count_denominations($body['denominations'] ?? null);
    if ($counted === null) {
        json_response(['success' => false, 'message' => $clean], 400);
    }
    $online = round((float) ($body['counted_online'] ?? 0), 2);
    if ($online < 0 || $online > 1000000000) {
        json_response(['success' => false, 'message' => 'Mobile-money balance is not valid.'], 400);
    }
    $note = trim((string) ($body['note'] ?? ''));
    if (mb_strlen($note) > 255) {
        json_response(['success' => false, 'message' => 'Note is too long (255 characters max).'], 400);
    }
    $c = ch_calculate($pdo, $date);
    $cashVar = round($counted - $c['expected_cash'], 2);
    $onlineVar = round($online - $c['expected_online'], 2);
    return [
        'opening_cash' => $c['opening_cash'], 'cash_sales' => $c['cash_sales'], 'online_sales' => $c['online_sales'],
        'credit_sales' => $c['credit_sales'], 'debt_cash' => $c['debt_cash'], 'debt_online' => $c['debt_online'],
        'expenses_cash' => $c['expenses_cash'], 'expenses_online' => $c['expenses_online'],
        'change_held' => $c['change_held'], 'change_paid' => $c['change_paid'],
        'expected_cash' => $c['expected_cash'], 'expected_online' => $c['expected_online'],
        'counted_cash' => $counted, 'counted_online' => $online,
        'cash_variance' => $cashVar, 'online_variance' => $onlineVar, 'variance' => round($cashVar + $onlineVar, 2),
        'denominations' => json_encode($clean), 'note' => $note ?: null,
    ];
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'preview') {
        $date = $_GET['date'] ?? today_str();
        if (!valid_date($date)) {
            json_response(['success' => false, 'message' => 'Invalid date.'], 400);
        }
        $st = $pdo->prepare('SELECT id FROM cash_audits WHERE audit_date=? ORDER BY id DESC LIMIT 1');
        $st->execute([$date]);
        $existingId = $st->fetchColumn();
        json_response([
            'success' => true,
            'date' => $date,
            'figures' => ch_calculate($pdo, $date),
            'denoms' => CASH_DENOMS,
            'audit' => $existingId ? audit_fetch($pdo, (int) $existingId) : null,
        ]);
    }

    if (!empty($_GET['id'])) {
        $a = audit_fetch($pdo, (int) $_GET['id']);
        if (!$a) {
            json_response(['success' => false, 'message' => 'Audit not found.'], 404);
        }
        $dep = $pdo->prepare('SELECT id, amount, bank_name, reference_no, receipt_file IS NOT NULL AS has_receipt, note, created_at FROM bank_deposits WHERE deposit_date=? ORDER BY id');
        $dep->execute([$a['audit_date']]);
        $a['deposits'] = $dep->fetchAll();
        json_response(['success' => true, 'audit' => $a]);
    }

    $where = [];
    $params = [];
    if (!empty($_GET['from']) && valid_date($_GET['from'])) { $where[] = 'a.audit_date >= ?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to']) && valid_date($_GET['to'])) { $where[] = 'a.audit_date <= ?'; $params[] = $_GET['to']; }
    $limit = (int) ($_GET['limit'] ?? 90);
    $limit = max(1, min($limit, 1000));
    $sql = 'SELECT a.*, u.full_name AS recorded_by_name FROM cash_audits a LEFT JOIN admins u ON u.id=a.recorded_by'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.audit_date DESC, a.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('audit_out', $st->fetchAll());

    if ($action === 'export') {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['audit_date'], $r['opening_cash'], $r['cash_sales'], $r['online_sales'], $r['credit_sales'], $r['debt_cash'], $r['debt_online'],
                $r['expenses_cash'], $r['expenses_online'], $r['change_held'], $r['change_paid'], $r['expected_cash'], $r['counted_cash'], $r['cash_variance'],
                $r['expected_online'], $r['counted_online'], $r['online_variance'], $r['variance'], $r['bank_deposit'], $r['remaining_cash'],
                csv_safe($r['recorded_by_name'] ?? ''), csv_safe($r['note'] ?? '')];
        }
        output_csv('cash_audits.csv', ['Date','Opening cash','Cash sales','Online sales','Credit sales','Debts paid (cash)','Debts paid (online)','Expenses (cash)','Expenses (online)','Change held','Change paid back','Expected cash','Counted cash','Cash variance','Expected online','Counted online','Online variance','Total variance','Banked','Left in office','Recorded by','Note'], $out);
    }

    $sum = ['audits' => count($rows), 'short' => 0, 'over' => 0, 'balanced' => 0, 'net_variance' => 0.0];
    foreach ($rows as $r) {
        $sum[$r['status']]++;
        $sum['net_variance'] += $r['variance'];
    }
    $sum['net_variance'] = round($sum['net_variance'], 2);
    json_response(['success' => true, 'audits' => $rows, 'summary' => $sum]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $date = (string) ($body['audit_date'] ?? today_str());
    assert_date_allowed($admin, $date);
    $dup = $pdo->prepare('SELECT COUNT(*) FROM cash_audits WHERE audit_date=?');
    $dup->execute([$date]);
    if ($dup->fetchColumn() > 0) {
        json_response(['success' => false, 'message' => 'A cash audit for ' . $date . ' is already saved. An admin can correct it from the history.'], 409);
    }
    $v = audit_build($pdo, $date, $body);
    $v['remaining_cash'] = $v['counted_cash'];
    $cols = array_merge(['audit_date' => $date, 'recorded_by' => $admin['id']], $v);
    $sql = 'INSERT INTO cash_audits (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($cols));
    $id = (int) $pdo->lastInsertId();
    log_activity($pdo, $admin, 'create', 'cash_audit', $id, "Cash audit {$date}: counted " . number_format($v['counted_cash']) . ", variance " . number_format($v['variance']));
    json_response(['success' => true, 'message' => 'Cash audit saved.', 'audit' => audit_fetch($pdo, $id)], 201);
}

if ($method === 'PUT') {
    require_non_worker();
    $body = read_json_body();
    $id = (int) ($body['id'] ?? 0);
    $old = $id ? audit_fetch($pdo, $id) : null;
    if (!$old) {
        json_response(['success' => false, 'message' => 'Audit not found.'], 404);
    }
    $v = audit_build($pdo, $old['audit_date'], $body);
    $set = [];
    foreach ($v as $k => $_) { $set[] = "$k=?"; }
    $pdo->prepare('UPDATE cash_audits SET ' . implode(',', $set) . ' WHERE id=?')->execute(array_merge(array_values($v), [$id]));
    ch_sync_deposits($pdo, $old['audit_date']);
    log_activity($pdo, $admin, 'update', 'cash_audit', $id, "Cash audit {$old['audit_date']} corrected: counted " . number_format($old['counted_cash']) . ' → ' . number_format($v['counted_cash']) . ', variance ' . number_format($old['variance']) . ' → ' . number_format($v['variance']));
    json_response(['success' => true, 'message' => 'Audit updated.', 'audit' => audit_fetch($pdo, $id)]);
}

if ($method === 'DELETE') {
    require_non_worker();
    $id = (int) ($_GET['id'] ?? 0);
    $old = $id ? audit_fetch($pdo, $id) : null;
    if (!$old) {
        json_response(['success' => false, 'message' => 'Audit not found.'], 404);
    }
    $n = $pdo->prepare('SELECT COUNT(*) FROM bank_deposits WHERE deposit_date=?');
    $n->execute([$old['audit_date']]);
    if ($n->fetchColumn() > 0) {
        json_response(['success' => false, 'message' => 'This day has bank deposits recorded. Delete those first, then the audit.'], 400);
    }
    $pdo->prepare('DELETE FROM cash_audits WHERE id=?')->execute([$id]);
    log_activity($pdo, $admin, 'delete', 'cash_audit', $id, "Cash audit {$old['audit_date']} deleted (counted " . number_format($old['counted_cash']) . ')');
    json_response(['success' => true, 'message' => 'Audit deleted.']);
}

json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
