<?php
/**
 * Products (stock). A product has one or more "types" (e.g. Pen -> Blue, Black).
 * Each type is its own row in `products` (so sales, purchases and damages keep
 * working per type); rows that belong to the same product share a `group_id`.
 *
 *  GET                -> flat list of types; `name` is the display name
 *                        ("Pen - Blue"), `base_name` is the product name.
 *  GET ?grouped=1     -> products with their `types` array (Stock page).
 *  POST (JSON)        -> create a product + its types.
 *  PUT  (JSON)        -> update a product + sync its types (by group_id).
 *  DELETE ?group_id=  -> delete a product with all its types.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function product_row_out(array $row): array
{
    $row['base_name'] = $row['name'];
    $row['variant'] = $row['variant'] ?? '';
    $row['name'] = item_display_name($row['name'], $row['variant']);
    $row['buy_price'] = (float) $row['buy_price'];
    $row['price'] = (float) $row['price'];
    $row['minimum_price'] = isset($row['minimum_price']) && $row['minimum_price'] !== null ? (float) $row['minimum_price'] : null;
    $row['stock'] = (int) $row['stock'];
    $row['low_stock_threshold'] = (int) $row['low_stock_threshold'];
    $row['is_low_stock'] = $row['stock'] <= $row['low_stock_threshold'];
    return $row;
}

/** Whole-inventory summary cards: total stock value, distinct products, total item quantity. */
function product_summary(PDO $pdo): array
{
    $row = $pdo->query('SELECT COUNT(DISTINCT group_id) AS total_products, COALESCE(SUM(stock),0) AS total_items,
                                COALESCE(SUM(stock * buy_price),0) AS total_value FROM products')->fetch();
    return [
        'total_products' => (int) $row['total_products'],
        'total_items' => (int) $row['total_items'],
        'total_value' => round((float) $row['total_value'], 2),
    ];
}

/** Validate + normalise the shared product fields and its types from a JSON body. */
function read_product_payload(array $data): array
{
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        json_response(['success' => false, 'message' => 'Product name is required.'], 400);
    }
    $typesIn = is_array($data['types'] ?? null) ? $data['types'] : [];
    if (!$typesIn) {
        json_response(['success' => false, 'message' => 'Add at least one type (use a blank type name if the product has only one).'], 400);
    }

    $types = [];
    $seen = [];
    foreach ($typesIn as $t) {
        $variant = trim($t['variant'] ?? '');
        $buy = (float) ($t['buy_price'] ?? 0);
        $price = (float) ($t['price'] ?? 0);
        $stock = (int) ($t['stock'] ?? 0);
        $min = isset($t['minimum_price']) && $t['minimum_price'] !== '' ? (float) $t['minimum_price'] : null;
        if ($min !== null && ($min < 0 || $min > $price)) {
            json_response(['success' => false, 'message' => 'The minimum price cannot be negative or above the selling price.'], 400);
        }
        $low = isset($t['low_stock_threshold']) && $t['low_stock_threshold'] !== '' ? (int) $t['low_stock_threshold'] : 5;
        if ($buy < 0 || $price < 0 || $stock < 0 || $low < 0) {
            json_response(['success' => false, 'message' => 'Prices and stock cannot be negative.'], 400);
        }
        $key = mb_strtolower($variant);
        if (isset($seen[$key])) {
            json_response(['success' => false, 'message' => 'Each type name must be different.'], 400);
        }
        $seen[$key] = true;
        $types[] = [
            'id' => (int) ($t['id'] ?? 0),
            'variant' => $variant,
            'buy_price' => $buy,
            'price' => $price,
            'stock' => $stock,
            'low_stock_threshold' => $low,
            'minimum_price' => $min,
        ];
    }

    return [
        'name' => $name,
        'description' => trim($data['description'] ?? ''),
        'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
        'status' => ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'types' => $types,
    ];
}

switch (true) {

    // ---------------------------------------------------------
    // GET: any logged-in user (admin, admin, or worker) can view stock.
    case $method === 'GET':
        require_admin_auth();

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name FROM products p
                                    LEFT JOIN categories c ON c.id = p.category_id
                                    WHERE p.id = ? LIMIT 1');
            $stmt->execute([(int) $_GET['id']]);
            $row = $stmt->fetch();
            if (!$row) {
                json_response(['success' => false, 'message' => 'Product not found.'], 404);
            }
            json_response(['success' => true, 'product' => product_row_out($row)]);
        }

        $where = [];
        $params = [];

        if (!empty($_GET['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['category'])) {
            $where[] = 'c.slug = ?';
            $params[] = $_GET['category'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(p.name LIKE ? OR p.variant LIKE ? OR p.description LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($_GET['low_stock'])) {
            $where[] = 'p.stock <= p.low_stock_threshold';
        }

        $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p
                LEFT JOIN categories c ON c.id = p.category_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.name ASC, p.variant ASC';

        $limit = (int) ($_GET['limit'] ?? 0);
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('product_row_out', $stmt->fetchAll());

        // Low-stock report can be downloaded directly as CSV.
        if (!empty($_GET['low_stock']) && ($_GET['download'] ?? '') === 'csv') {
            $csvRows = array_map(fn($p) => [$p['name'], $p['category_name'] ?? '', $p['stock'], $p['low_stock_threshold'], $p['price']], $rows);
            output_csv('low_stock_report.csv', ['Product', 'Category', 'Current Stock', 'Low Stock Threshold', 'Sell Price'], $csvRows);
        }

        if (!empty($_GET['grouped'])) {
            $groups = [];
            foreach ($rows as $r) {
                $gid = (int) $r['group_id'];
                if (!isset($groups[$gid])) {
                    $groups[$gid] = [
                        'group_id' => $gid,
                        'name' => $r['base_name'],
                        'description' => $r['description'],
                        'category_id' => $r['category_id'],
                        'category_name' => $r['category_name'],
                        'status' => $r['status'],
                        'total_stock' => 0,
                        'has_low_stock' => false,
                        'types' => [],
                    ];
                }
                $groups[$gid]['total_stock'] += $r['stock'];
                $groups[$gid]['has_low_stock'] = $groups[$gid]['has_low_stock'] || $r['is_low_stock'];
                $groups[$gid]['types'][] = $r;
            }
            json_response(['success' => true, 'groups' => array_values($groups), 'summary' => product_summary($pdo)]);
        }

        json_response(['success' => true, 'products' => $rows, 'count' => count($rows), 'summary' => product_summary($pdo)]);
        break;

    // ---------------------------------------------------------
    // Add a new product with its types (admins/super admins only)
    case $method === 'POST':
        $admin = require_non_worker();
        $p = read_product_payload(read_json_body());

        $pdo->beginTransaction();
        try {
            $groupId = null;
            $ins = $pdo->prepare('INSERT INTO products (category_id, name, variant, description, buy_price, price, stock, low_stock_threshold, status, group_id, minimum_price)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($p['types'] as $t) {
                $ins->execute([$p['category_id'], $p['name'], $t['variant'], $p['description'], $t['buy_price'], $t['price'], $t['stock'], $t['low_stock_threshold'], $p['status'], $groupId, $t['minimum_price']]);
                if ($groupId === null) {
                    $groupId = (int) $pdo->lastInsertId();
                    $pdo->prepare('UPDATE products SET group_id = id WHERE id = ?')->execute([$groupId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not save product.'], 500);
        }

        log_activity($pdo, $admin, 'create', 'product', $groupId, 'Product "' . $p['name'] . '" added with ' . count($p['types']) . ' type(s)');
        json_response(['success' => true, 'message' => 'Product added.', 'group_id' => $groupId], 201);
        break;

    // ---------------------------------------------------------
    // Update a product and sync its types (admins/super admins only).
    // Types with an id are updated, types without one are added, and
    // existing types missing from the payload are removed.
    case $method === 'PUT':
        $admin = require_non_worker();
        $data = read_json_body();
        $groupId = (int) ($data['group_id'] ?? 0);
        if (!$groupId) {
            json_response(['success' => false, 'message' => 'Missing product id.'], 400);
        }
        $p = read_product_payload($data);

        $existingStmt = $pdo->prepare('SELECT id FROM products WHERE group_id = ?');
        $existingStmt->execute([$groupId]);
        $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$existingIds) {
            json_response(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $pdo->beginTransaction();
        try {
            $keepIds = [];
            $upd = $pdo->prepare('UPDATE products SET category_id = ?, name = ?, variant = ?, description = ?, buy_price = ?, price = ?, stock = ?, low_stock_threshold = ?, status = ?, minimum_price = ? WHERE id = ? AND group_id = ?');
            $ins = $pdo->prepare('INSERT INTO products (category_id, name, variant, description, buy_price, price, stock, low_stock_threshold, status, group_id, minimum_price)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($p['types'] as $t) {
                if ($t['id'] && in_array($t['id'], $existingIds, true)) {
                    $upd->execute([$p['category_id'], $p['name'], $t['variant'], $p['description'], $t['buy_price'], $t['price'], $t['stock'], $t['low_stock_threshold'], $p['status'], $t['minimum_price'], $t['id'], $groupId]);
                    $keepIds[] = $t['id'];
                } else {
                    $ins->execute([$p['category_id'], $p['name'], $t['variant'], $p['description'], $t['buy_price'], $t['price'], $t['stock'], $t['low_stock_threshold'], $p['status'], $groupId, $t['minimum_price']]);
                    $keepIds[] = (int) $pdo->lastInsertId();
                }
            }
            $remove = array_diff($existingIds, $keepIds);
            if ($remove) {
                $in = implode(',', array_map('intval', $remove));
                $pdo->exec("DELETE FROM products WHERE group_id = $groupId AND id IN ($in)");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not update product.'], 500);
        }

        log_activity($pdo, $admin, 'update', 'product', $groupId, 'Product "' . $p['name'] . '" edited (' . count($p['types']) . ' type(s))');
        json_response(['success' => true, 'message' => 'Product updated.']);
        break;

    // ---------------------------------------------------------
    case $method === 'DELETE':
        $admin = require_non_worker();
        $groupId = (int) ($_GET['group_id'] ?? 0);
        if (!$groupId) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $pdo->prepare('DELETE FROM products WHERE group_id = ?')->execute([$groupId]);
        log_activity($pdo, $admin, 'delete', 'product', $groupId, 'Product group #' . $groupId . ' deleted');
        json_response(['success' => true, 'message' => 'Product deleted.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
