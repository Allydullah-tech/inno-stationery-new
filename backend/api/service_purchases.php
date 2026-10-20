<?php
/**
 * Service purchases — materials bought for the SERVICES side of the shop
 * (paper for printing, toner, lamination film ...). Kept separate from
 * product stock. Sales of a service can consume these materials.
 *
 *  GET  ?view=items                 picker list (everyone)
 *  GET  ?view=stock                 stock with used / left
 *  GET  ?view=profit&from=&to=      service revenue vs material cost (admins)
 *  GET  ?from=&to=&search=          purchase history (admins see costs)
 *  POST {purchase_date, paid_from, note, items:[{name,description,unit,quantity,buying_price}]}   (admins)
 *  POST ?action=waste {service_item_id, quantity, reason, note, date}  record spoiled/lost material (admins)
 *  PUT  {id, quantity, buying_price, purchase_date, paid_from, note}   (admins)
 *  DELETE ?id=                      (admins)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_material_helper.php';

send_api_headers();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$admin = require_admin_auth();
$worker = is_worker($admin);

const SP_PAID_FROM = ['cash', 'online', 'other'];

function sp_item_out(array $r, bool $worker): array
{
    $r['stock_quantity'] = (int) $r['stock_quantity'];
    $r['buying_price'] = (float) $r['buying_price'];
    if ($worker) {
        unset($r['buying_price'], $r['stock_value'], $r['total_spent']);
    }
    return $r;
}

if ($method === 'GET') {
    $view = $_GET['view'] ?? '';

    if ($view === 'items') {
        $rows = $pdo->query('SELECT * FROM service_items ORDER BY name, description')->fetchAll();
        foreach ($rows as &$r) { $r['label'] = sm_display_name($r); $r = sp_item_out($r, $worker); }
        json_response(['success' => true, 'items' => $rows]);
    }

    if ($view === 'stock') {
        $rows = $pdo->query('SELECT i.*, COALESCE(p.qty,0) AS total_purchased, COALESCE(p.spent,0) AS total_spent
            FROM service_items i LEFT JOIN (SELECT service_item_id, SUM(quantity) qty, SUM(total_cost) spent FROM service_purchases GROUP BY service_item_id) p ON p.service_item_id=i.id
            ORDER BY i.name, i.description')->fetchAll();
        $totalValue = 0;
        foreach ($rows as &$r) {
            $r['total_purchased'] = (int) $r['total_purchased'];
            $r['total_used'] = max($r['total_purchased'] - (int) $r['stock_quantity'], 0);
            $r['stock_value'] = round((int) $r['stock_quantity'] * (float) $r['buying_price'], 2);
            $r['total_spent'] = (float) $r['total_spent'];
            $totalValue += $r['stock_value'];
            $r['label'] = sm_display_name($r);
            $r = sp_item_out($r, $worker);
        }
        json_response(['success' => true, 'items' => $rows, 'stock_value' => $worker ? null : round($totalValue, 2)]);
    }

    if ($view === 'profit') {
        require_non_worker();
        $period = $_GET['period'] ?? 'monthly';
        [$from, $to] = resolve_report_range($period, $_GET['from'] ?? null, $_GET['to'] ?? null);
        $rev = $pdo->prepare("SELECT COALESCE(SUM(s.total_amount),0) revenue,
                COALESCE(SUM(CASE WHEN d.id IS NOT NULL AND d.original_amount>0 THEN s.total_amount * (d.balance/d.original_amount) ELSE 0 END),0) unpaid
            FROM sales s LEFT JOIN debts d ON d.sale_id = s.id WHERE s.item_type='service' AND s.sale_date BETWEEN ? AND ?");
        $rev->execute([$from, $to]);
        $r = $rev->fetch();
        $cost = $pdo->prepare('SELECT COALESCE(SUM(total_cost),0) FROM service_purchases WHERE purchase_date BETWEEN ? AND ?');
        $cost->execute([$from, $to]);
        $c = (float) $cost->fetchColumn();
        $waste = $pdo->prepare("SELECT COALESCE(SUM(loss_amount),0) FROM damages WHERE category='Service material' AND damage_date BETWEEN ? AND ?");
        $waste->execute([$from, $to]);
        $revenue = round((float) $r['revenue'], 2);
        $unpaid = round((float) $r['unpaid'], 2);
        json_response(['success' => true, 'from' => $from, 'to' => $to,
            'service_sales' => $revenue, 'unpaid_credit' => $unpaid, 'collected' => round($revenue - $unpaid, 2),
            'materials_bought' => round($c, 2), 'materials_wasted' => round((float) $waste->fetchColumn(), 2),
            'profit' => round($revenue - $unpaid - $c, 2)]);
    }

    // purchase history
    $where = []; $params = [];
    if (!empty($_GET['from']) && valid_date($_GET['from'])) { $where[] = 'p.purchase_date >= ?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to']) && valid_date($_GET['to'])) { $where[] = 'p.purchase_date <= ?'; $params[] = $_GET['to']; }
    if (!empty($_GET['search'])) { $where[] = '(i.name LIKE ? OR i.description LIKE ?)'; $q = '%' . $_GET['search'] . '%'; array_push($params, $q, $q); }
    $limit = max(1, min((int) ($_GET['limit'] ?? 200), 1000));
    $st = $pdo->prepare('SELECT p.*, i.name AS item_name, i.description AS item_description, i.unit, u.full_name AS recorded_by_name
        FROM service_purchases p JOIN service_items i ON i.id=p.service_item_id LEFT JOIN admins u ON u.id=p.recorded_by'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.purchase_date DESC, p.id DESC LIMIT ' . $limit);
    $st->execute($params);
    $rows = $st->fetchAll();
    $total = 0;
    foreach ($rows as &$r) {
        $r['quantity'] = (int) $r['quantity']; $r['buying_price'] = (float) $r['buying_price']; $r['total_cost'] = (float) $r['total_cost'];
        $total += $r['total_cost'];
        $r['label'] = sm_display_name(['name' => $r['item_name'], 'description' => $r['item_description']]);
        if ($worker) { unset($r['buying_price'], $r['total_cost']); }
    }
    json_response(['success' => true, 'purchases' => $rows, 'total_cost' => $worker ? null : round($total, 2)]);
}

require_non_worker();

if ($method === 'POST' && ($_GET['action'] ?? '') === 'waste') {
    $b = read_json_body();
    $id = (int) ($b['service_item_id'] ?? 0);
    $qty = (int) ($b['quantity'] ?? 0);
    $date = (string) ($b['date'] ?? today_str());
    $reason = in_array($b['reason'] ?? '', ['expired', 'damaged', 'other'], true) ? $b['reason'] : 'damaged';
    $note = trim((string) ($b['note'] ?? ''));
    assert_date_allowed($admin, $date);
    if ($qty <= 0 || mb_strlen($note) > 255) {
        json_response(['success' => false, 'message' => 'Enter a valid quantity (and a note under 255 characters).'], 400);
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM service_items WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        $item = $st->fetch();
        if (!$item) { throw new RuntimeException('Material not found.'); }
        if ((int) $item['stock_quantity'] < $qty) { throw new RuntimeException('Only ' . (int) $item['stock_quantity'] . ' left in stock — you cannot write off more than that.'); }
        $loss = round($qty * (float) $item['buying_price'], 2);
        $pdo->prepare('UPDATE service_items SET stock_quantity = stock_quantity - ? WHERE id=?')->execute([$qty, $id]);
        $pdo->prepare("INSERT INTO damages (is_stock_item, product_id, product_name, category, quantity, reason, unit_cost, loss_amount, notes, recorded_by, damage_date) VALUES (0,NULL,?,?,?,?,?,?,?,?,?)")
            ->execute([sm_display_name($item), 'Service material', $qty, $reason, $item['buying_price'], $loss, $note ?: null, $admin['id'], $date]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()], 400);
    }
    log_activity($pdo, $admin, 'create', 'service_waste', $id, "Wrote off {$qty} × " . sm_display_name($item) . " ({$reason}), loss " . number_format($loss));
    json_response(['success' => true, 'message' => 'Written off and recorded as a loss.'], 201);
}

if ($method === 'POST') {
    $b = read_json_body();
    $date = (string) ($b['purchase_date'] ?? today_str());
    $paidFrom = in_array($b['paid_from'] ?? 'other', SP_PAID_FROM, true) ? $b['paid_from'] : 'other';
    $note = trim((string) ($b['note'] ?? ''));
    $items = $b['items'] ?? [];
    assert_date_allowed($admin, $date);
    if (!is_array($items) || !$items || count($items) > 200) {
        json_response(['success' => false, 'message' => 'Add at least one material.'], 400);
    }
    if (mb_strlen($note) > 255) {
        json_response(['success' => false, 'message' => 'Note is too long.'], 400);
    }
    $clean = [];
    foreach ($items as $i => $it) {
        $name = trim((string) ($it['name'] ?? ''));
        $desc = trim((string) ($it['description'] ?? ''));
        $unit = trim((string) ($it['unit'] ?? '')) ?: 'pcs';
        $qty = (int) ($it['quantity'] ?? 0);
        $price = round((float) ($it['buying_price'] ?? 0), 2);
        $row = $i + 1;
        if ($name === '' || mb_strlen($name) > 150 || mb_strlen($desc) > 190 || mb_strlen($unit) > 30) {
            json_response(['success' => false, 'message' => "Row {$row}: material name is required (and within length limits)."], 400);
        }
        if ($qty <= 0 || $qty > 10000000 || $price <= 0 || $price > 1000000000) {
            json_response(['success' => false, 'message' => "Row {$row}: enter a quantity and a buying price above zero."], 400);
        }
        $clean[] = compact('name', 'desc', 'unit', 'qty', 'price');
    }
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT * FROM service_items WHERE name = ? AND description = ? FOR UPDATE');
        foreach ($clean as $c) {
            $find->execute([$c['name'], $c['desc']]);
            $item = $find->fetch();
            if ($item) {
                $pdo->prepare('UPDATE service_items SET stock_quantity = stock_quantity + ?, buying_price = ?, unit = ? WHERE id = ?')->execute([$c['qty'], $c['price'], $c['unit'], $item['id']]);
                $itemId = (int) $item['id'];
            } else {
                $pdo->prepare('INSERT INTO service_items (name, description, unit, stock_quantity, buying_price) VALUES (?,?,?,?,?)')->execute([$c['name'], $c['desc'], $c['unit'], $c['qty'], $c['price']]);
                $itemId = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO service_purchases (service_item_id, quantity, buying_price, total_cost, purchase_date, paid_from, note, recorded_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$itemId, $c['qty'], $c['price'], round($c['qty'] * $c['price'], 2), $date, $paidFrom, $note ?: null, $admin['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Could not save the purchase. Nothing was changed.'], 500);
    }
    $sum = array_sum(array_map(fn($c) => $c['qty'] * $c['price'], $clean));
    log_activity($pdo, $admin, 'create', 'service_purchase', null, count($clean) . ' service material(s) bought for ' . number_format($sum) . " on {$date} (paid from {$paidFrom})");
    json_response(['success' => true, 'message' => count($clean) . ' material(s) added to service stock.'], 201);
}

if ($method === 'PUT') {
    $b = read_json_body();
    $id = (int) ($b['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM service_purchases WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) { throw new RuntimeException('Purchase not found.'); }
        $qty = (int) ($b['quantity'] ?? $p['quantity']);
        $price = round((float) ($b['buying_price'] ?? $p['buying_price']), 2);
        $date = (string) ($b['purchase_date'] ?? $p['purchase_date']);
        $paid = in_array($b['paid_from'] ?? $p['paid_from'], SP_PAID_FROM, true) ? ($b['paid_from'] ?? $p['paid_from']) : $p['paid_from'];
        $note = array_key_exists('note', $b) ? trim((string) $b['note']) : $p['note'];
        if ($qty <= 0 || $price <= 0 || !valid_date($date) || $date > today_str() || mb_strlen((string) $note) > 255) {
            throw new RuntimeException('Check the quantity, price and date.');
        }
        $delta = $qty - (int) $p['quantity'];
        $it = $pdo->prepare('SELECT * FROM service_items WHERE id=? FOR UPDATE');
        $it->execute([$p['service_item_id']]);
        $item = $it->fetch();
        if ((int) $item['stock_quantity'] + $delta < 0) {
            throw new RuntimeException('Part of this purchase has already been used, so the quantity cannot go that low.');
        }
        $pdo->prepare('UPDATE service_items SET stock_quantity = stock_quantity + ? WHERE id=?')->execute([$delta, $item['id']]);
        $latest = $pdo->prepare('SELECT MAX(id) FROM service_purchases WHERE service_item_id=?');
        $latest->execute([$item['id']]);
        if ((int) $latest->fetchColumn() === $id) {
            $pdo->prepare('UPDATE service_items SET buying_price=? WHERE id=?')->execute([$price, $item['id']]);
        }
        $pdo->prepare('UPDATE service_purchases SET quantity=?, buying_price=?, total_cost=?, purchase_date=?, paid_from=?, note=? WHERE id=?')
            ->execute([$qty, $price, round($qty * $price, 2), $date, $paid, $note ?: null, $id]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()], 400);
    }
    log_activity($pdo, $admin, 'update', 'service_purchase', $id, 'Service purchase edited: ' . sm_display_name($item) . " qty {$p['quantity']} → {$qty}, price " . number_format((float) $p['buying_price']) . ' → ' . number_format($price));
    json_response(['success' => true, 'message' => 'Purchase updated.']);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM service_purchases WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) { throw new RuntimeException('Purchase not found.'); }
        $it = $pdo->prepare('SELECT * FROM service_items WHERE id=? FOR UPDATE');
        $it->execute([$p['service_item_id']]);
        $item = $it->fetch();
        if ((int) $item['stock_quantity'] < (int) $p['quantity']) {
            throw new RuntimeException('Part of this purchase has already been used, so it cannot be deleted.');
        }
        $pdo->prepare('DELETE FROM service_purchases WHERE id=?')->execute([$id]);
        $pdo->prepare('UPDATE service_items SET stock_quantity = stock_quantity - ? WHERE id=?')->execute([$p['quantity'], $item['id']]);
        $left = $pdo->prepare('SELECT (SELECT COUNT(*) FROM service_purchases WHERE service_item_id=?) + (SELECT COUNT(*) FROM sale_materials WHERE service_item_id=?)');
        $left->execute([$item['id'], $item['id']]);
        if ((int) $left->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM service_items WHERE id=?')->execute([$item['id']]);
        }
        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()], 400);
    }
    log_activity($pdo, $admin, 'delete', 'service_purchase', $id, 'Service purchase removed: ' . $p['quantity'] . ' × ' . sm_display_name($item));
    json_response(['success' => true, 'message' => 'Purchase deleted.']);
}

json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
