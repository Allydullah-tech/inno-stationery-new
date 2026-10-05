<?php
/**
 * Sales — supports selling either a physical PRODUCT (stock-based) or a
 * SERVICE (non-stock). Cash/credit workflow and debt creation are shared
 * between both. Editing a sale re-validates and re-applies everything:
 * stock deltas, debt totals, and the linked debt record (if any).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

const CASH_PAYMENT_METHODS = ['cash_in_hand', 'mpesa', 'airtel_money', 'halopesa', 'mixx_by_yas'];

function sale_row_out(array $row): array
{
    $row['quantity'] = (int) $row['quantity'];
    $row['unit_price'] = (float) $row['unit_price'];
    $row['list_price'] = $row['list_price'] !== null ? (float) $row['list_price'] : null;
    $row['unit_cost'] = $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null;
    $row['total_amount'] = (float) $row['total_amount'];
    // Profit is only meaningful when the cost is actually known.
    $row['profit'] = $row['unit_cost'] !== null
        ? round(($row['unit_price'] - $row['unit_cost']) * $row['quantity'], 2)
        : null;
    $row['has_discount'] = $row['list_price'] !== null && $row['list_price'] > $row['unit_price'];
    if (isset($row['debt_balance'])) {
        $row['debt_balance'] = $row['debt_balance'] !== null ? (float) $row['debt_balance'] : null;
    }
    if (isset($row['debt_amount_paid'])) {
        $row['debt_amount_paid'] = $row['debt_amount_paid'] !== null ? (float) $row['debt_amount_paid'] : null;
    }
    if (array_key_exists('debt_status', $row) && $row['debt_status'] !== null && isset($row['debt_balance'], $row['debt_amount_paid'], $row['debt_deadline'])) {
        $row['debt_status'] = compute_debt_status((float) $row['debt_balance'], (float) $row['debt_amount_paid'], $row['debt_deadline']);
    }
    return $row;
}

/**
 * Validate + normalize the common "what am I selling, how, and to whom"
 * payload shared by both creating and editing a sale.
 * Returns ['error' => string] on failure, or the parsed fields on success.
 */
function parse_sale_payload(PDO $pdo, array $body): array
{
    $itemType = ($body['item_type'] ?? 'product') === 'service' ? 'service' : 'product';
    $quantity = (int) ($body['quantity'] ?? 0);
    $requestedUnitPrice = isset($body['unit_price']) && $body['unit_price'] !== '' ? (float) $body['unit_price'] : null;
    $notes = trim($body['notes'] ?? '');
    $saleDate = !empty($body['sale_date']) ? $body['sale_date'] : date('Y-m-d');

    if ($quantity <= 0) {
        return ['error' => 'Please enter a valid quantity.'];
    }
    if ($requestedUnitPrice !== null && $requestedUnitPrice < 0) {
        return ['error' => 'Selling price cannot be negative.'];
    }
    if (!DateTime::createFromFormat('Y-m-d', $saleDate)) {
        return ['error' => 'Please provide a valid sale date.'];
    }

    $product = null;
    $service = null;

    if ($itemType === 'product') {
        $productId = (int) ($body['product_id'] ?? 0);
        if (!$productId) {
            return ['error' => 'Please choose a product.'];
        }
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            return ['error' => 'Product not found.'];
        }
        $itemName = $product['name'];
        $listPrice = (float) $product['price'];
        $unitCost = (float) $product['buy_price'];
    } else {
        $serviceId = (int) ($body['service_id'] ?? 0);
        if (!$serviceId) {
            return ['error' => 'Please choose a service.'];
        }
        $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();
        if (!$service) {
            return ['error' => 'Service not found.'];
        }
        $itemName = $service['name'];
        $listPrice = (float) $service['selling_price'];
        // Preserve the unknown/zero distinction — do NOT default to 0.
        $unitCost = $service['buying_cost'] !== null ? (float) $service['buying_cost'] : null;
    }

    $unitPrice = $requestedUnitPrice ?? $listPrice;
    $totalAmount = round($unitPrice * $quantity, 2);

    $saleType = $body['sale_type'] ?? 'cash';
    if (!in_array($saleType, ['cash', 'credit'], true)) {
        return ['error' => 'Please select a valid sale type (Cash Sale or Sale on Credit).'];
    }

    $paymentMethod = null;
    $customerName = '';
    $customerPhone = '';
    $paymentDeadline = '';
    $creditNotes = '';

    if ($saleType === 'cash') {
        $paymentMethod = $body['payment_method'] ?? '';
        if (!in_array($paymentMethod, CASH_PAYMENT_METHODS, true)) {
            return ['error' => 'Please select a payment method for this cash sale.'];
        }
    } else {
        $paymentMethod = 'credit';
        $customerName = trim($body['customer_name'] ?? '');
        $customerPhone = trim($body['customer_phone'] ?? '');
        $paymentDeadline = trim($body['payment_deadline'] ?? '');
        $creditNotes = trim($body['credit_notes'] ?? '');

        if ($customerName === '' || $customerPhone === '' || $paymentDeadline === '') {
            return ['error' => 'Customer name, phone number, and payment deadline are required for a credit sale.'];
        }
        if (!DateTime::createFromFormat('Y-m-d', $paymentDeadline)) {
            return ['error' => 'Please provide a valid payment deadline date.'];
        }
    }

    return [
        'item_type' => $itemType,
        'product' => $product,
        'service' => $service,
        'item_name' => $itemName,
        'quantity' => $quantity,
        'list_price' => $listPrice,
        'unit_price' => $unitPrice,
        'unit_cost' => $unitCost,
        'total_amount' => $totalAmount,
        'sale_date' => $saleDate,
        'notes' => $notes,
        'sale_type' => $saleType,
        'payment_method' => $paymentMethod,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'payment_deadline' => $paymentDeadline,
        'credit_notes' => $creditNotes,
    ];
}

switch ($method) {

    // List sales (any logged-in user; optional date range filter)
    case 'GET':
        require_admin_auth();
        $where = [];
        $params = [];
        if (!empty($_GET['from'])) {
            $where[] = 's.sale_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 's.sale_date <= ?';
            $params[] = $_GET['to'];
        }
        if (!empty($_GET['item_type']) && in_array($_GET['item_type'], ['product', 'service'], true)) {
            $where[] = 's.item_type = ?';
            $params[] = $_GET['item_type'];
        }
        if (!empty($_GET['search'])) {
            $where[] = 's.product_name LIKE ?';
            $params[] = '%' . $_GET['search'] . '%';
        }
        $sql = 'SELECT s.*, a.full_name AS recorded_by_name,
                       d.id AS debt_id, d.status AS debt_status, d.balance AS debt_balance,
                       d.amount_paid AS debt_amount_paid, d.payment_deadline AS debt_deadline
                FROM sales s
                LEFT JOIN admins a ON a.id = s.recorded_by
                LEFT JOIN debts d ON d.sale_id = s.id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.sale_date DESC, s.created_at DESC';

        $limit = (int) ($_GET['limit'] ?? 0);
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['success' => true, 'sales' => array_map('sale_row_out', $stmt->fetchAll())]);
        break;

    // Record a sale of a product OR a service (admins AND workers).
    // sale_type: 'cash' -> requires payment_method | 'credit' -> creates a debt.
    case 'POST':
        $admin = require_admin_auth();
        $body = read_json_body();

        $f = parse_sale_payload($pdo, $body);
        if (isset($f['error'])) {
            json_response(['success' => false, 'message' => $f['error']], 400);
        }

        if ($f['item_type'] === 'product' && $f['product']['stock'] < $f['quantity']) {
            json_response(['success' => false, 'message' => 'Not enough stock. Only ' . $f['product']['stock'] . ' left.'], 400);
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO sales
                (item_type, product_id, service_id, product_name, quantity, list_price, unit_price, unit_cost, total_amount, sale_type, payment_method, notes, recorded_by, sale_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $f['item_type'],
                $f['item_type'] === 'product' ? $f['product']['id'] : null,
                $f['item_type'] === 'service' ? $f['service']['id'] : null,
                $f['item_name'], $f['quantity'], $f['list_price'], $f['unit_price'], $f['unit_cost'],
                $f['total_amount'], $f['sale_type'], $f['payment_method'], $f['notes'] ?: null, $admin['id'], $f['sale_date'],
            ]);
            $saleId = (int) $pdo->lastInsertId();

            if ($f['item_type'] === 'product') {
                $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$f['quantity'], $f['product']['id']]);
            }

            $debtId = null;
            if ($f['sale_type'] === 'credit') {
                $status = compute_debt_status($f['total_amount'], 0, $f['payment_deadline']);
                $insertDebt = $pdo->prepare('INSERT INTO debts
                    (sale_id, customer_name, customer_phone, original_amount, amount_paid, balance, payment_deadline, status, notes, recorded_by, sale_date)
                    VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)');
                $insertDebt->execute([
                    $saleId, $f['customer_name'], $f['customer_phone'], $f['total_amount'], $f['total_amount'],
                    $f['payment_deadline'], $status, $f['credit_notes'] ?: null, $admin['id'], $f['sale_date'],
                ]);
                $debtId = (int) $pdo->lastInsertId();
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not record sale. Please try again.'], 500);
        }

        json_response([
            'success' => true,
            'message' => $f['sale_type'] === 'credit' ? 'Credit sale recorded. Debt created for ' . $f['customer_name'] . '.' : 'Sale recorded.',
            'id' => $saleId,
            'debt_id' => $debtId,
        ], 201);
        break;

    // Edit an existing sale (admins/super admins only). Recalculates stock
    // and the linked debt (if any). Blocked once the debt has payments
    // recorded — edit history stays clean by design; delete + re-enter
    // instead in that case, or edit only after removing the payments.
    case 'PUT':
        $admin = require_non_worker();
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing sale id.'], 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(['success' => false, 'message' => 'Sale not found.'], 404);
        }

        $debtStmt = $pdo->prepare('SELECT * FROM debts WHERE sale_id = ? LIMIT 1');
        $debtStmt->execute([$id]);
        $existingDebt = $debtStmt->fetch();
        if ($existingDebt && (float) $existingDebt['amount_paid'] > 0) {
            json_response(['success' => false, 'message' => 'This credit sale already has payments recorded against it and cannot be edited. Remove the payment history first from the Debts page if you need to change it.'], 400);
        }

        $f = parse_sale_payload($pdo, $body);
        if (isset($f['error'])) {
            json_response(['success' => false, 'message' => $f['error']], 400);
        }

        // Stock check: pretend the old product quantity was already
        // returned, then verify the new requirement fits.
        if ($f['item_type'] === 'product') {
            $availableStock = (int) $f['product']['stock'];
            if ($existing['item_type'] === 'product' && (int) $existing['product_id'] === (int) $f['product']['id']) {
                $availableStock += (int) $existing['quantity'];
            }
            if ($availableStock < $f['quantity']) {
                json_response(['success' => false, 'message' => 'Not enough stock. Only ' . $availableStock . ' available.'], 400);
            }
        }

        $pdo->beginTransaction();
        try {
            // 1. Reverse the old stock effect (if it was a product sale).
            if ($existing['item_type'] === 'product' && $existing['product_id']) {
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$existing['quantity'], $existing['product_id']]);
            }
            // 2. Apply the new stock effect (if it's now a product sale).
            if ($f['item_type'] === 'product') {
                $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$f['quantity'], $f['product']['id']]);
            }

            // 3. Update the sale row itself.
            $update = $pdo->prepare('UPDATE sales SET
                item_type = ?, product_id = ?, service_id = ?, product_name = ?, quantity = ?,
                list_price = ?, unit_price = ?, unit_cost = ?, total_amount = ?,
                sale_type = ?, payment_method = ?, notes = ?, sale_date = ?
                WHERE id = ?');
            $update->execute([
                $f['item_type'],
                $f['item_type'] === 'product' ? $f['product']['id'] : null,
                $f['item_type'] === 'service' ? $f['service']['id'] : null,
                $f['item_name'], $f['quantity'], $f['list_price'], $f['unit_price'], $f['unit_cost'],
                $f['total_amount'], $f['sale_type'], $f['payment_method'], $f['notes'] ?: null, $f['sale_date'], $id,
            ]);

            // 4. Keep the linked debt in sync (create / update / remove).
            $debtId = $existingDebt ? (int) $existingDebt['id'] : null;
            if ($f['sale_type'] === 'credit') {
                $status = compute_debt_status($f['total_amount'], 0, $f['payment_deadline']);
                if ($existingDebt) {
                    $pdo->prepare('UPDATE debts SET customer_name = ?, customer_phone = ?, original_amount = ?, balance = ?, payment_deadline = ?, status = ?, notes = ?, sale_date = ? WHERE id = ?')
                        ->execute([$f['customer_name'], $f['customer_phone'], $f['total_amount'], $f['total_amount'], $f['payment_deadline'], $status, $f['credit_notes'] ?: null, $f['sale_date'], $existingDebt['id']]);
                } else {
                    $insertDebt = $pdo->prepare('INSERT INTO debts
                        (sale_id, customer_name, customer_phone, original_amount, amount_paid, balance, payment_deadline, status, notes, recorded_by, sale_date)
                        VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)');
                    $insertDebt->execute([
                        $id, $f['customer_name'], $f['customer_phone'], $f['total_amount'], $f['total_amount'],
                        $f['payment_deadline'], $status, $f['credit_notes'] ?: null, $admin['id'], $f['sale_date'],
                    ]);
                    $debtId = (int) $pdo->lastInsertId();
                }
            } elseif ($existingDebt) {
                // Switched from credit back to cash — safe, since we already
                // confirmed above that no payments exist on this debt.
                $pdo->prepare('DELETE FROM debts WHERE id = ?')->execute([$existingDebt['id']]);
                $debtId = null;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not update sale. Please try again.'], 500);
        }

        json_response(['success' => true, 'message' => 'Sale updated.', 'id' => $id, 'debt_id' => $debtId]);
        break;

    // Delete a sale record (admins/super admins only — restores stock)
    case 'DELETE':
        require_non_worker();
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$id]);
        $sale = $stmt->fetch();
        if (!$sale) {
            json_response(['success' => false, 'message' => 'Sale not found.'], 404);
        }

        $debtStmt = $pdo->prepare('SELECT * FROM debts WHERE sale_id = ? LIMIT 1');
        $debtStmt->execute([$id]);
        $debt = $debtStmt->fetch();
        if ($debt && (float) $debt['amount_paid'] > 0) {
            json_response(['success' => false, 'message' => 'This credit sale already has payments recorded against it and cannot be deleted. Delete the payment history first from the Debts page.'], 400);
        }

        $pdo->beginTransaction();
        try {
            if ($debt) {
                $pdo->prepare('DELETE FROM debts WHERE id = ?')->execute([$debt['id']]);
            }
            $pdo->prepare('DELETE FROM sales WHERE id = ?')->execute([$id]);
            if ($sale['item_type'] === 'product' && $sale['product_id']) {
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$sale['quantity'], $sale['product_id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not delete sale.'], 500);
        }

        json_response(['success' => true, 'message' => 'Sale deleted' . ($sale['item_type'] === 'product' ? ' and stock restored' : '') . '.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
