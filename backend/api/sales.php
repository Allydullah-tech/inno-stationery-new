<?php
/**
 * Sales — a "receipt" can hold several lines (products and/or services),
 * all sharing one payment (cash / mobile money / credit) and one customer.
 *
 *  GET    ?from=&to=&item_type=&search=&limit=   flat list of sale lines
 *  GET    ?receipt=RC-XXXXXX                    one receipt with its lines
 *  POST   {items:[...], sale_type, payment_method, customer_*, ...}
 *  PUT    {receipt_no | id, items:[...], ...}    admins: replaces the receipt
 *  DELETE ?receipt=RC-XXXXXX | ?id=              admins: removes it, restores stock
 *
 * Old single-item payloads (product_id / service_id at the top level) still work.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_material_helper.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

const CASH_PAYMENT_METHODS = ['cash_in_hand', 'mpesa', 'airtel_money', 'halopesa', 'mixx_by_yas'];
const MAX_CART_LINES = 60;

function sale_row_out(array $row, bool $hideCost = false): array
{
    $row['quantity'] = (int) $row['quantity'];
    $row['unit_price'] = (float) $row['unit_price'];
    $row['list_price'] = $row['list_price'] !== null ? (float) $row['list_price'] : null;
    $row['unit_cost'] = $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null;
    $row['total_amount'] = (float) $row['total_amount'];
    $row['profit'] = $row['unit_cost'] !== null ? round(($row['unit_price'] - $row['unit_cost']) * $row['quantity'], 2) : null;
    $row['has_discount'] = $row['list_price'] !== null && $row['list_price'] > $row['unit_price'];
    foreach (['debt_balance', 'debt_amount_paid'] as $k) {
        if (isset($row[$k])) { $row[$k] = (float) $row[$k]; }
    }
    if (!empty($row['debt_status']) && isset($row['debt_balance'], $row['debt_amount_paid'], $row['debt_deadline'])) {
        $row['debt_status'] = compute_debt_status((float) $row['debt_balance'], (float) $row['debt_amount_paid'], $row['debt_deadline']);
    }
    if ($hideCost) {
        unset($row['unit_cost'], $row['profit']);
    }
    return $row;
}

/** Validate the shared part of the payload (payment, customer, date). */
function parse_receipt_header(array $admin, array $b, bool $isEdit): array
{
    $saleDate = !empty($b['sale_date']) ? (string) $b['sale_date'] : today_str();
    if ($isEdit && !is_worker($admin)) {
        if (!valid_date($saleDate) || $saleDate > today_str()) {
            json_response(['success' => false, 'message' => 'Please provide a valid sale date (not in the future).'], 400);
        }
    } else {
        assert_date_allowed($admin, $saleDate);
    }
    $saleType = $b['sale_type'] ?? 'cash';
    if (!in_array($saleType, ['cash', 'credit'], true)) {
        json_response(['success' => false, 'message' => 'Please select Cash Sale or Sale on Credit.'], 400);
    }
    $notes = trim((string) ($b['notes'] ?? ''));
    $custName = trim((string) ($b['customer_name'] ?? ''));
    $custPhone = trim((string) ($b['customer_phone'] ?? ''));
    if (mb_strlen($notes) > 255 || mb_strlen($custName) > 150 || mb_strlen($custPhone) > 40) {
        json_response(['success' => false, 'message' => 'Notes, customer name or phone is too long.'], 400);
    }
    $h = ['sale_date' => $saleDate, 'sale_type' => $saleType, 'notes' => $notes, 'customer_name' => $custName, 'customer_phone' => $custPhone,
          'payment_method' => null, 'payment_deadline' => '', 'credit_notes' => '', 'change_left' => 0.0];

    if ($saleType === 'cash') {
        $pm = $b['payment_method'] ?? '';
        if (!in_array($pm, CASH_PAYMENT_METHODS, true)) {
            json_response(['success' => false, 'message' => 'Please select a payment method for this cash sale.'], 400);
        }
        $h['payment_method'] = $pm;
        if (($custName === '') !== ($custPhone === '')) {
            json_response(['success' => false, 'message' => 'Give both the customer name and phone, or leave both empty.'], 400);
        }
        $change = round((float) ($b['change_left'] ?? 0), 2);
        if ($change < 0 || $change > 10000000) {
            json_response(['success' => false, 'message' => 'Change left with the shop is not valid.'], 400);
        }
        if ($change > 0) {
            if ($pm !== 'cash_in_hand') {
                json_response(['success' => false, 'message' => 'Change can only be left with the shop on a cash-in-hand sale.'], 400);
            }
            if ($custName === '') {
                json_response(['success' => false, 'message' => 'Enter the customer name and phone so the change can be handed back later.'], 400);
            }
        }
        $h['change_left'] = $change;
    } else {
        $h['payment_method'] = 'credit';
        $h['payment_deadline'] = trim((string) ($b['payment_deadline'] ?? ''));
        $h['credit_notes'] = trim((string) ($b['credit_notes'] ?? ''));
        if ($custName === '' || $custPhone === '' || $h['payment_deadline'] === '') {
            json_response(['success' => false, 'message' => 'Customer name, phone number, and payment deadline are required for a credit sale.'], 400);
        }
        if (!valid_date($h['payment_deadline']) || $h['payment_deadline'] < $saleDate) {
            json_response(['success' => false, 'message' => 'The payment deadline must be a valid date on or after the sale date.'], 400);
        }
        if (normalize_phone($custPhone) === '' || strlen(normalize_phone($custPhone)) < 7) {
            json_response(['success' => false, 'message' => 'Please enter a valid customer phone number.'], 400);
        }
    }
    return $h;
}

/** Validate + price the cart lines. Stock is re-checked inside the transaction. */
function parse_cart_lines(PDO $pdo, array $admin, array $b): array
{
    $items = $b['items'] ?? null;
    if ($items === null && (!empty($b['product_id']) || !empty($b['service_id']))) {
        $items = [[
            'item_type' => $b['item_type'] ?? (!empty($b['service_id']) ? 'service' : 'product'),
            'product_id' => $b['product_id'] ?? null, 'service_id' => $b['service_id'] ?? null,
            'quantity' => $b['quantity'] ?? 0, 'unit_price' => $b['unit_price'] ?? null, 'materials' => $b['materials'] ?? [],
        ]];
    }
    if (!is_array($items) || !$items) {
        json_response(['success' => false, 'message' => 'Add at least one item to the sale.'], 400);
    }
    if (count($items) > MAX_CART_LINES) {
        json_response(['success' => false, 'message' => 'A receipt can hold up to ' . MAX_CART_LINES . ' lines.'], 400);
    }
    $lines = [];
    foreach ($items as $i => $it) {
        $n = $i + 1;
        $type = ($it['item_type'] ?? 'product') === 'service' ? 'service' : 'product';
        $qty = (int) ($it['quantity'] ?? 0);
        if ($qty <= 0 || $qty > 1000000) {
            json_response(['success' => false, 'message' => "Line {$n}: enter a valid quantity."], 400);
        }
        $req = isset($it['unit_price']) && $it['unit_price'] !== '' ? round((float) $it['unit_price'], 2) : null;
        if ($req !== null && ($req < 0 || $req > 1000000000)) {
            json_response(['success' => false, 'message' => "Line {$n}: the selling price is not valid."], 400);
        }
        if ($type === 'product') {
            $st = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
            $st->execute([(int) ($it['product_id'] ?? 0)]);
            $row = $st->fetch();
            if (!$row) { json_response(['success' => false, 'message' => "Line {$n}: product not found."], 400); }
            $name = item_display_name($row['name'], $row['variant'] ?? null);
            $list = (float) $row['price'];
            $cost = (float) $row['buy_price'];
        } else {
            $st = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
            $st->execute([(int) ($it['service_id'] ?? 0)]);
            $row = $st->fetch();
            if (!$row) { json_response(['success' => false, 'message' => "Line {$n}: service not found."], 400); }
            $name = item_display_name($row['name'], $row['variant'] ?? null);
            $list = (float) $row['selling_price'];
            $cost = 0.0;
        }
        if (($row['status'] ?? 'active') !== 'active') {
            json_response(['success' => false, 'message' => "Line {$n}: {$name} is inactive and cannot be sold."], 400);
        }
        $price = $req ?? $list;
        $min = $row['minimum_price'] ?? null;
        if ($min !== null && $price + 0.001 < (float) $min) {
            json_response(['success' => false, 'message' => "Line {$n}: {$name} cannot be sold below its minimum price of " . number_format((float) $min) . '.'], 400);
        }
        if ($type === 'product' && is_worker($admin) && $price + 0.001 < $cost) {
            json_response(['success' => false, 'message' => "Line {$n}: a worker cannot sell {$name} below its buying price. Ask an admin."], 400);
        }
        $materials = [];
        if ($type === 'service' && !empty($it['materials']) && is_array($it['materials'])) {
            foreach ($it['materials'] as $m) {
                $mid = (int) ($m['service_item_id'] ?? 0); $mq = (int) ($m['quantity'] ?? 0);
                if ($mid > 0 && $mq > 0) { $materials[] = ['service_item_id' => $mid, 'quantity' => $mq]; }
            }
        }
        $lines[] = ['type' => $type, 'id' => (int) $row['id'], 'name' => $name, 'qty' => $qty, 'list' => $list, 'price' => $price,
                    'cost' => $cost, 'total' => round($price * $qty, 2), 'materials' => $materials];
    }
    return $lines;
}

/** Fetch every line of a receipt (or the single legacy row when there is no receipt number). */
function fetch_receipt_lines(PDO $pdo, ?string $receipt, int $id): array
{
    if ($receipt) {
        $st = $pdo->prepare('SELECT * FROM sales WHERE receipt_no = ? ORDER BY id');
        $st->execute([$receipt]);
    } else {
        $st = $pdo->prepare('SELECT * FROM sales WHERE id = ? ORDER BY id');
        $st->execute([$id]);
    }
    return $st->fetchAll();
}

/** Undo everything a receipt did (stock, materials, debt, held change) and delete its rows. Inside a transaction. */
function reverse_receipt(PDO $pdo, array $lines): void
{
    $ids = array_column($lines, 'id');
    $receipt = $lines[0]['receipt_no'] ?? null;
    foreach ($lines as $l) {
        if ($l['item_type'] === 'product' && $l['product_id']) {
            $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$l['quantity'], $l['product_id']]);
        }
        sm_restore($pdo, (int) $l['id']);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM debts WHERE sale_id IN ($in)" . ($receipt ? ' OR receipt_no = ?' : ''))->execute($receipt ? array_merge($ids, [$receipt]) : $ids);
    if ($receipt) {
        $pdo->prepare('DELETE FROM change_holds WHERE receipt_no = ?')->execute([$receipt]);
    }
    $pdo->prepare("DELETE FROM sales WHERE id IN ($in)")->execute($ids);
}

/** Why a receipt cannot be edited/deleted, or null when it can. */
function receipt_lock_reason(PDO $pdo, array $lines): ?string
{
    $ids = array_column($lines, 'id');
    $receipt = $lines[0]['receipt_no'] ?? null;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM debts WHERE sale_id IN ($in)" . ($receipt ? ' OR receipt_no = ?' : ''));
    $st->execute($receipt ? array_merge($ids, [$receipt]) : $ids);
    if ((float) $st->fetchColumn() > 0) {
        return 'This credit sale already has payments recorded against it. Remove the payments from the Debts page first.';
    }
    if ($receipt) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM change_collections cc JOIN change_holds h ON h.id = cc.hold_id WHERE h.receipt_no = ?');
        $c->execute([$receipt]);
        if ($c->fetchColumn() > 0) {
            return 'Change from this sale has already been handed back, so it cannot be changed.';
        }
    }
    return null;
}

/** Insert the receipt; returns ['receipt_no','ids','total','debt_id']. Inside a transaction. Throws RuntimeException on business errors. */
function write_receipt(PDO $pdo, array $admin, array $h, array $lines, ?array $keep = null): array
{
    // Lock + check product stock per product across ALL lines of the cart.
    $need = [];
    foreach ($lines as $l) {
        if ($l['type'] === 'product') { $need[$l['id']] = ($need[$l['id']] ?? 0) + $l['qty']; }
    }
    foreach ($need as $pid => $q) {
        $st = $pdo->prepare('SELECT name, variant, stock FROM products WHERE id = ? FOR UPDATE');
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p || (int) $p['stock'] < $q) {
            throw new RuntimeException('Not enough stock for ' . ($p ? item_display_name($p['name'], $p['variant']) : 'a product') . '. Only ' . ($p ? (int) $p['stock'] : 0) . ' left, ' . $q . ' needed.');
        }
    }

    $customer = null;
    if ($h['customer_name'] !== '' && $h['customer_phone'] !== '') {
        $customer = upsert_customer($pdo, $h['customer_name'], $h['customer_phone']);
        if ($customer && $h['sale_type'] === 'credit') {
            refresh_customer_risk($pdo, (int) $customer['id']);
            $cs = $pdo->prepare('SELECT status, restriction_reason FROM customers WHERE id = ?');
            $cs->execute([$customer['id']]);
            $now = $cs->fetch();
            if ($now && $now['status'] === 'restricted') {
                throw new RuntimeException($customer['name'] . ' is restricted from buying on credit (' . ($now['restriction_reason'] ?: 'overdue debts') . '). An admin can lift this on the Customers page.');
            }
        }
    }
    $customerId = $customer ? (int) $customer['id'] : null;
    $custName = $customer ? $customer['name'] : $h['customer_name'];

    $receipt = $keep['receipt_no'] ?? null;
    if (!$receipt) {
        do {
            $receipt = generate_reference_code('RC');
            $chk = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE receipt_no = ?');
            $chk->execute([$receipt]);
        } while ($chk->fetchColumn() > 0);
    }
    $recordedBy = $keep['recorded_by'] ?? $admin['id'];
    $createdAt = $keep['created_at'] ?? date('Y-m-d H:i:s');

    $ins = $pdo->prepare('INSERT INTO sales (item_type, product_id, service_id, product_name, quantity, list_price, unit_price, unit_cost, total_amount, sale_type, payment_method, notes, recorded_by, sale_date, created_at, receipt_no, customer_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ids = [];
    $total = 0.0;
    foreach ($lines as $l) {
        $ins->execute([$l['type'], $l['type'] === 'product' ? $l['id'] : null, $l['type'] === 'service' ? $l['id'] : null, $l['name'], $l['qty'],
            $l['list'], $l['price'], $l['cost'], $l['total'], $h['sale_type'], $h['payment_method'], $h['notes'] ?: null, $recordedBy, $h['sale_date'], $createdAt, $receipt, $customerId]);
        $sid = (int) $pdo->lastInsertId();
        $ids[] = $sid;
        $total += $l['total'];
        if ($l['type'] === 'product') {
            $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$l['qty'], $l['id']]);
        } elseif ($l['materials']) {
            sm_deduct($pdo, $sid, $l['materials']);
        }
    }
    $total = round($total, 2);

    $debtId = null;
    if ($h['sale_type'] === 'credit') {
        $status = compute_debt_status($total, 0, $h['payment_deadline']);
        $pdo->prepare('INSERT INTO debts (sale_id, customer_name, customer_phone, original_amount, amount_paid, balance, payment_deadline, status, notes, recorded_by, sale_date, customer_id, receipt_no)
            VALUES (?,?,?,?,0,?,?,?,?,?,?,?,?)')
            ->execute([$ids[0], $custName, $h['customer_phone'], $total, $total, $h['payment_deadline'], $status, $h['credit_notes'] ?: null, $recordedBy, $h['sale_date'], $customerId, $receipt]);
        $debtId = (int) $pdo->lastInsertId();
    }
    if ($h['change_left'] > 0) {
        $pdo->prepare('INSERT INTO change_holds (receipt_no, customer_name, customer_phone, amount, hold_date, recorded_by) VALUES (?,?,?,?,?,?)')
            ->execute([$receipt, $custName, normalize_phone($h['customer_phone']), $h['change_left'], $h['sale_date'], $recordedBy]);
    }
    return ['receipt_no' => $receipt, 'ids' => $ids, 'total' => $total, 'debt_id' => $debtId];
}

switch ($method) {

    case 'GET':
        $admin = require_admin_auth();
        $hide = is_worker($admin);
        $base = 'SELECT s.*, a.full_name AS recorded_by_name, c.name AS customer_name, c.phone AS customer_phone,
                        d.id AS debt_id, d.status AS debt_status, d.balance AS debt_balance,
                        d.amount_paid AS debt_amount_paid, d.payment_deadline AS debt_deadline
                 FROM sales s
                 LEFT JOIN admins a ON a.id = s.recorded_by
                 LEFT JOIN customers c ON c.id = s.customer_id
                 LEFT JOIN debts d ON (d.sale_id = s.id OR (s.receipt_no IS NOT NULL AND d.receipt_no = s.receipt_no))';

        if (!empty($_GET['receipt'])) {
            $st = $pdo->prepare($base . ' WHERE s.receipt_no = ? ORDER BY s.id');
            $st->execute([$_GET['receipt']]);
            $rows = $st->fetchAll();
            if (!$rows) { json_response(['success' => false, 'message' => 'Receipt not found.'], 404); }
            $lines = [];
            foreach ($rows as $r) {
                $o = sale_row_out($r, $hide);
                $m = $pdo->prepare('SELECT sm.quantity, i.name, i.description FROM sale_materials sm JOIN service_items i ON i.id = sm.service_item_id WHERE sm.sale_id = ?');
                $m->execute([$r['id']]);
                $o['materials'] = array_map(fn($x) => ['label' => sm_display_name($x), 'quantity' => (int) $x['quantity']], $m->fetchAll());
                $lines[] = $o;
            }
            $h = $rows[0];
            $hold = $pdo->prepare('SELECT amount FROM change_holds WHERE receipt_no = ? LIMIT 1');
            $hold->execute([$_GET['receipt']]);
            json_response(['success' => true, 'receipt' => [
                'receipt_no' => $h['receipt_no'], 'sale_date' => $h['sale_date'], 'sale_type' => $h['sale_type'], 'payment_method' => $h['payment_method'],
                'notes' => $h['notes'], 'customer_name' => $h['customer_name'], 'customer_phone' => $h['customer_phone'],
                'recorded_by_name' => $h['recorded_by_name'], 'created_at' => $h['created_at'],
                'total' => round(array_sum(array_column($lines, 'total_amount')), 2),
                'debt_id' => $h['debt_id'], 'debt_balance' => $h['debt_balance'] !== null ? (float) $h['debt_balance'] : null,
                'debt_deadline' => $h['debt_deadline'], 'change_left' => (float) ($hold->fetchColumn() ?: 0),
                'lines' => $lines,
            ], 'shop' => ['name' => get_setting('site_name', "INNO's Stationery"), 'currency' => get_setting('currency_symbol', 'TSh')]]);
        }

        $where = []; $params = [];
        if (!empty($_GET['from'])) { $where[] = 's.sale_date >= ?'; $params[] = $_GET['from']; }
        if (!empty($_GET['to'])) { $where[] = 's.sale_date <= ?'; $params[] = $_GET['to']; }
        if (!empty($_GET['item_type']) && in_array($_GET['item_type'], ['product', 'service'], true)) { $where[] = 's.item_type = ?'; $params[] = $_GET['item_type']; }
        if (!empty($_GET['sale_type']) && in_array($_GET['sale_type'], ['cash', 'credit'], true)) { $where[] = 's.sale_type = ?'; $params[] = $_GET['sale_type']; }
        if (!empty($_GET['search'])) {
            $where[] = '(s.product_name LIKE ? OR s.receipt_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
            $q = '%' . $_GET['search'] . '%';
            array_push($params, $q, $q, $q, $q);
        }
        $sql = $base . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.sale_date DESC, s.created_at DESC, s.id DESC';
        $limit = (int) ($_GET['limit'] ?? 0);
        if ($limit > 0) { $sql .= ' LIMIT ' . min($limit, 20000); }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_response(['success' => true, 'sales' => array_map(fn($r) => sale_row_out($r, $hide), $st->fetchAll())]);
        break;

    case 'POST':
        $admin = require_admin_auth();
        $body = read_json_body();
        $h = parse_receipt_header($admin, $body, false);
        $lines = parse_cart_lines($pdo, $admin, $body);
        $pdo->beginTransaction();
        try {
            $res = write_receipt($pdo, $admin, $h, $lines);
            $pdo->commit();
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('sale create failed: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Could not record the sale. Nothing was saved — please try again.'], 500);
        }
        log_activity($pdo, $admin, 'create', 'sale', $res['receipt_no'], count($lines) . ' line(s), total ' . number_format($res['total']) . ' (' . ($h['sale_type'] === 'credit' ? 'credit for ' . $h['customer_name'] : $h['payment_method']) . ')');
        json_response([
            'success' => true,
            'message' => $h['sale_type'] === 'credit' ? 'Credit sale recorded. Debt created for ' . $h['customer_name'] . '.' : 'Sale recorded.',
            'receipt_no' => $res['receipt_no'], 'id' => $res['ids'][0], 'ids' => $res['ids'], 'total' => $res['total'], 'debt_id' => $res['debt_id'],
        ], 201);
        break;

    case 'PUT':
        $admin = require_non_worker();
        $body = read_json_body();
        $lines0 = fetch_receipt_lines($pdo, !empty($body['receipt_no']) ? (string) $body['receipt_no'] : null, (int) ($body['id'] ?? 0));
        if (!$lines0) {
            json_response(['success' => false, 'message' => 'Sale not found.'], 404);
        }
        if ($why = receipt_lock_reason($pdo, $lines0)) {
            json_response(['success' => false, 'message' => $why], 400);
        }
        $h = parse_receipt_header($admin, $body, true);
        $newLines = parse_cart_lines($pdo, $admin, $body);
        $keep = ['receipt_no' => $lines0[0]['receipt_no'], 'recorded_by' => $lines0[0]['recorded_by'], 'created_at' => $lines0[0]['created_at']];
        $pdo->beginTransaction();
        try {
            reverse_receipt($pdo, $lines0);
            $res = write_receipt($pdo, $admin, $h, $newLines, $keep);
            $pdo->commit();
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('sale update failed: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Could not update the sale. Nothing was changed.'], 500);
        }
        $oldTotal = array_sum(array_column($lines0, 'total_amount'));
        log_activity($pdo, $admin, 'update', 'sale', $res['receipt_no'], 'Receipt edited: total ' . number_format($oldTotal) . ' → ' . number_format($res['total']) . ', ' . count($newLines) . ' line(s)');
        json_response(['success' => true, 'message' => 'Sale updated.', 'receipt_no' => $res['receipt_no'], 'id' => $res['ids'][0], 'debt_id' => $res['debt_id']]);
        break;

    case 'DELETE':
        $admin = require_non_worker();
        $lines = fetch_receipt_lines($pdo, !empty($_GET['receipt']) ? (string) $_GET['receipt'] : null, (int) ($_GET['id'] ?? 0));
        if (!$lines) {
            json_response(['success' => false, 'message' => 'Sale not found.'], 404);
        }
        if ($why = receipt_lock_reason($pdo, $lines)) {
            json_response(['success' => false, 'message' => $why], 400);
        }
        $pdo->beginTransaction();
        try {
            reverse_receipt($pdo, $lines);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('sale delete failed: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Could not delete the sale.'], 500);
        }
        log_activity($pdo, $admin, 'delete', 'sale', $lines[0]['receipt_no'] ?: $lines[0]['id'], 'Sale deleted: ' . count($lines) . ' line(s), total ' . number_format(array_sum(array_column($lines, 'total_amount'))));
        json_response(['success' => true, 'message' => 'Sale deleted and stock restored.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
