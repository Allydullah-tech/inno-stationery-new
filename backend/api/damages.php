<?php
/**
 * Damages / losses. Two kinds of record:
 *   - Stock item (is_stock_item=1): tied to a product, decrements stock.
 *   - Non-stock item (is_stock_item=0): equipment, tools, office assets,
 *     or anything never registered in the products table. Entered
 *     manually and never touches product stock.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function damage_row_out(array $row): array
{
    $row['is_stock_item'] = (bool) $row['is_stock_item'];
    $row['quantity'] = (int) $row['quantity'];
    $row['unit_cost'] = (float) $row['unit_cost'];
    $row['loss_amount'] = (float) $row['loss_amount'];
    return $row;
}

switch ($method) {

    case 'GET':
        require_admin_auth();
        $where = [];
        $params = [];
        if (!empty($_GET['from'])) {
            $where[] = 'd.damage_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 'd.damage_date <= ?';
            $params[] = $_GET['to'];
        }
        if (($_GET['scope'] ?? '') === 'stock') {
            $where[] = 'd.is_stock_item = 1';
        } elseif (($_GET['scope'] ?? '') === 'non_stock') {
            $where[] = 'd.is_stock_item = 0';
        }
        if (!empty($_GET['search'])) {
            $where[] = '(d.product_name LIKE ? OR d.category LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql = 'SELECT d.*, a.full_name AS recorded_by_name FROM damages d
                LEFT JOIN admins a ON a.id = d.recorded_by';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY d.damage_date DESC, d.created_at DESC';

        $limit = (int) ($_GET['limit'] ?? 0);
        if ($limit > 0) {
            $sql .= ' LIMIT ' . min($limit, 20000);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['success' => true, 'damages' => array_map('damage_row_out', $stmt->fetchAll())]);
        break;

    // Record damaged/expired stock OR a non-stock asset (admins AND workers).
    // Only stock items decrement product stock.
    case 'POST':
        $admin = require_admin_auth();
        $body = read_json_body();

        $isStockItem = ($body['is_stock_item'] ?? true);
        $isStockItem = $isStockItem === false || $isStockItem === 'false' || $isStockItem === '0' || $isStockItem === 0 ? false : true;

        $quantity = (int) ($body['quantity'] ?? 0);
        $reason = in_array($body['reason'] ?? '', ['expired', 'damaged', 'other'], true) ? $body['reason'] : 'damaged';
        $notes = trim($body['notes'] ?? '');
        $damageDate = !empty($body['damage_date']) ? (string) $body['damage_date'] : today_str();
        assert_date_allowed($admin, $damageDate);
        if (mb_strlen($notes) > 255) {
            json_response(['success' => false, 'message' => 'Notes are too long (255 characters max).'], 400);
        }

        if ($quantity <= 0 || $quantity > 1000000) {
            json_response(['success' => false, 'message' => 'Please enter a valid quantity.'], 400);
        }

        if ($isStockItem) {
            $productId = (int) ($body['product_id'] ?? 0);
            if (!$productId) {
                json_response(['success' => false, 'message' => 'Please choose a product.'], 400);
            }
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) {
                json_response(['success' => false, 'message' => 'Product not found.'], 404);
            }
            if ($product['stock'] < $quantity) {
                json_response(['success' => false, 'message' => 'Only ' . $product['stock'] . ' in stock — cannot mark more than that as damaged.'], 400);
            }
            $itemName = item_display_name($product['name'], $product['variant'] ?? null);
            $category = null;
            $unitCost = (float) $product['buy_price'];
        } else {
            $productId = null;
            $product = null;
            $itemName = trim($body['item_name'] ?? '');
            $category = trim($body['category'] ?? '');
            if ($itemName === '') {
                json_response(['success' => false, 'message' => 'Please enter the item name.'], 400);
            }
            $unitCost = isset($body['unit_cost']) && $body['unit_cost'] !== '' ? (float) $body['unit_cost'] : 0;
            if ($unitCost < 0) {
                json_response(['success' => false, 'message' => 'Value cannot be negative.'], 400);
            }
        }

        $lossAmount = round($unitCost * $quantity, 2);

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO damages
                (is_stock_item, product_id, product_name, category, quantity, reason, unit_cost, loss_amount, notes, recorded_by, damage_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $isStockItem ? 1 : 0, $productId, $itemName, $category ?: null, $quantity, $reason,
                $unitCost, $lossAmount, $notes ?: null, $admin['id'], $damageDate,
            ]);
            $newId = $pdo->lastInsertId();
            if ($isStockItem) {
                $lock = $pdo->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
                $lock->execute([$productId]);
                if ((int) $lock->fetchColumn() < $quantity) {
                    throw new RuntimeException('Not enough stock left to write off that quantity.');
                }
                $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$quantity, $productId]);
            }
            $pdo->commit();
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not record damage. Please try again.'], 500);
        }
        log_activity($pdo, $admin, 'create', 'damage', $newId, "{$quantity} × {$itemName} ({$reason}), loss " . number_format($lossAmount));

        json_response(['success' => true, 'message' => 'Damage recorded.', 'id' => $newId], 201);
        break;

    // Delete a damage record (admins/super admins only — restores stock only if it was a stock item)
    case 'DELETE':
        $admin = require_non_worker();
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $stmt = $pdo->prepare('SELECT * FROM damages WHERE id = ?');
        $stmt->execute([$id]);
        $damage = $stmt->fetch();
        if (!$damage) {
            json_response(['success' => false, 'message' => 'Record not found.'], 404);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM damages WHERE id = ?')->execute([$id]);
            if ($damage['is_stock_item'] && $damage['product_id']) {
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$damage['quantity'], $damage['product_id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not delete record.'], 500);
        }

        log_activity($pdo, $admin, 'delete', 'damage', $id, 'Damage record deleted: ' . $damage['quantity'] . ' × ' . $damage['product_name'] . ', loss ' . number_format((float) $damage['loss_amount']));
        json_response(['success' => true, 'message' => 'Damage record deleted' . ($damage['is_stock_item'] ? ' and stock restored' : '') . '.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
