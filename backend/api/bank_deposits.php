<?php
/**
 * Bank deposits — cash banked after the day's audit. The amount can never
 * exceed the cash counted in that day's saved audit. A photo/PDF of the
 * bank slip can be attached.
 *
 *  GET  ?date=  | ?from=&to=     list
 *  POST (multipart/form-data or JSON)  deposit_date, amount, bank_name, reference_no, note, receipt (file)
 *  DELETE ?id=                   admins only
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cash_helper.php';

send_api_headers();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$admin = require_admin_auth();
$dir = BACKEND_ROOT . '/uploads/bank_receipts/';

function dep_out(array $r): array
{
    $r['amount'] = (float) $r['amount'];
    $r['has_receipt'] = !empty($r['receipt_file']);
    unset($r['receipt_file']);
    return $r;
}

if ($method === 'GET') {
    $where = [];
    $params = [];
    if (!empty($_GET['date'])) { $where[] = 'd.deposit_date = ?'; $params[] = $_GET['date']; }
    if (!empty($_GET['from'])) { $where[] = 'd.deposit_date >= ?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to'])) { $where[] = 'd.deposit_date <= ?'; $params[] = $_GET['to']; }
    $sql = 'SELECT d.*, a.full_name AS recorded_by_name FROM bank_deposits d LEFT JOIN admins a ON a.id=d.recorded_by'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY d.deposit_date DESC, d.id DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = ['success' => true, 'deposits' => array_map('dep_out', $st->fetchAll())];
    if (!empty($_GET['date'])) {
        $au = $pdo->prepare('SELECT counted_cash, bank_deposit, remaining_cash FROM cash_audits WHERE audit_date=? ORDER BY id DESC LIMIT 1');
        $au->execute([$_GET['date']]);
        $a = $au->fetch();
        $out['bankable'] = $a ? round((float) $a['counted_cash'] - (float) $a['bank_deposit'], 2) : null;
    }
    json_response($out);
}

if ($method === 'POST') {
    $isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') === 0;
    $b = $isMultipart ? $_POST : read_json_body();
    $date = (string) ($b['deposit_date'] ?? today_str());
    $amount = round((float) ($b['amount'] ?? 0), 2);
    $bank = trim((string) ($b['bank_name'] ?? ''));
    $ref = trim((string) ($b['reference_no'] ?? ''));
    $note = trim((string) ($b['note'] ?? ''));
    assert_date_allowed($admin, $date);
    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'Please enter the amount banked.'], 400);
    }
    if (mb_strlen($bank) > 100 || mb_strlen($ref) > 80 || mb_strlen($note) > 255) {
        json_response(['success' => false, 'message' => 'One of the text fields is too long.'], 400);
    }
    $au = $pdo->prepare('SELECT counted_cash, bank_deposit FROM cash_audits WHERE audit_date=? ORDER BY id DESC LIMIT 1');
    $au->execute([$date]);
    $audit = $au->fetch();
    if (!$audit) {
        json_response(['success' => false, 'message' => 'Save the cash audit for ' . $date . ' first — a deposit can only come out of counted cash.'], 400);
    }
    $bankable = round((float) $audit['counted_cash'] - (float) $audit['bank_deposit'], 2);
    if ($amount > $bankable + 0.001) {
        json_response(['success' => false, 'message' => 'Only ' . number_format($bankable) . ' of counted cash is left to bank for this date.'], 400);
    }

    $file = null; $mime = null;
    if (!empty($_FILES['receipt']['tmp_name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['receipt'];
        if ($f['size'] > 6 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'Receipt file is too large (6MB max).'], 400);
        }
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
        if (!$ext) {
            json_response(['success' => false, 'message' => 'Receipt must be a JPG, PNG, WEBP or PDF file.'], 400);
        }
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        $file = 'dep_' . str_replace('-', '', $date) . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . $file)) {
            json_response(['success' => false, 'message' => 'Could not save the receipt file.'], 500);
        }
    }

    $pdo->prepare('INSERT INTO bank_deposits (deposit_date, amount, bank_name, reference_no, receipt_file, receipt_mime, note, recorded_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$date, $amount, $bank ?: null, $ref ?: null, $file, $mime, $note ?: null, $admin['id']]);
    $id = (int) $pdo->lastInsertId();
    ch_sync_deposits($pdo, $date);
    log_activity($pdo, $admin, 'create', 'bank_deposit', $id, 'Banked ' . number_format($amount) . " on {$date}" . ($bank ? " ({$bank})" : ''));
    json_response(['success' => true, 'message' => 'Deposit recorded.', 'id' => $id], 201);
}

if ($method === 'DELETE') {
    require_non_worker();
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM bank_deposits WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        json_response(['success' => false, 'message' => 'Deposit not found.'], 404);
    }
    $pdo->prepare('DELETE FROM bank_deposits WHERE id=?')->execute([$id]);
    if ($row['receipt_file'] && is_file($dir . basename($row['receipt_file']))) {
        @unlink($dir . basename($row['receipt_file']));
    }
    ch_sync_deposits($pdo, $row['deposit_date']);
    log_activity($pdo, $admin, 'delete', 'bank_deposit', $id, 'Deposit of ' . number_format((float) $row['amount']) . " on {$row['deposit_date']} removed");
    json_response(['success' => true, 'message' => 'Deposit removed.']);
}

json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
