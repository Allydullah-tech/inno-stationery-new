<?php
/**
 * Cash float — the cash the shop starts the day with.
 *  GET  ?date=            summary (opening, top-ups, cash taken out) + entries
 *  POST                   {float_date, entry_type: opening|add|reduce, amount, note}
 *                         'opening' (overriding the carried-over cash) is admin-only.
 *  DELETE ?id=            admins only
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cash_helper.php';

send_api_headers();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$admin = require_admin_auth();

if ($method === 'GET') {
    $date = $_GET['date'] ?? today_str();
    if (!valid_date($date)) {
        json_response(['success' => false, 'message' => 'Invalid date.'], 400);
    }
    $st = $pdo->prepare('SELECT f.*, a.full_name AS recorded_by_name FROM cash_floats f LEFT JOIN admins a ON a.id=f.recorded_by WHERE f.float_date=? ORDER BY f.id');
    $st->execute([$date]);
    $entries = $st->fetchAll();
    foreach ($entries as &$e) { $e['amount'] = (float) $e['amount']; }
    json_response(['success' => true, 'date' => $date, 'summary' => ch_float_summary($pdo, $date), 'entries' => $entries]);
}

if ($method === 'POST') {
    $b = read_json_body();
    $date = (string) ($b['float_date'] ?? today_str());
    $type = (string) ($b['entry_type'] ?? '');
    $amount = round((float) ($b['amount'] ?? 0), 2);
    $note = trim((string) ($b['note'] ?? ''));
    assert_date_allowed($admin, $date);
    if (!in_array($type, ['opening', 'add', 'reduce'], true)) {
        json_response(['success' => false, 'message' => 'Choose opening, add or reduce.'], 400);
    }
    if ($type === 'opening') {
        require_non_worker();
    }
    if ($amount < 0 || ($type !== 'opening' && $amount <= 0) || $amount > 1000000000) {
        json_response(['success' => false, 'message' => 'Please enter a valid amount.'], 400);
    }
    if ($note === '' || mb_strlen($note) > 255) {
        json_response(['success' => false, 'message' => 'A short reason is required (up to 255 characters).'], 400);
    }
    $audit = $pdo->prepare('SELECT COUNT(*) FROM cash_audits WHERE audit_date=?');
    $audit->execute([$date]);
    if ($audit->fetchColumn() > 0) {
        json_response(['success' => false, 'message' => 'The cash audit for this date is already saved, so its float can no longer change.'], 400);
    }
    if ($type === 'reduce') {
        $cur = ch_float_summary($pdo, $date)['total'];
        if ($amount > $cur + 0.001) {
            json_response(['success' => false, 'message' => 'You can only take out up to the current float (' . number_format($cur) . ').'], 400);
        }
    }
    $pdo->prepare('INSERT INTO cash_floats (float_date, entry_type, amount, note, recorded_by) VALUES (?,?,?,?,?)')
        ->execute([$date, $type, $amount, $note, $admin['id']]);
    log_activity($pdo, $admin, 'create', 'cash_float', $pdo->lastInsertId(), "Float {$type} " . number_format($amount) . " for {$date}: {$note}");
    json_response(['success' => true, 'message' => 'Float updated.', 'summary' => ch_float_summary($pdo, $date)], 201);
}

if ($method === 'DELETE') {
    require_non_worker();
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM cash_floats WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        json_response(['success' => false, 'message' => 'Entry not found.'], 404);
    }
    $pdo->prepare('DELETE FROM cash_floats WHERE id=?')->execute([$id]);
    log_activity($pdo, $admin, 'delete', 'cash_float', $id, "Float {$row['entry_type']} " . number_format((float) $row['amount']) . " on {$row['float_date']} removed");
    json_response(['success' => true, 'message' => 'Entry removed.']);
}

json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
