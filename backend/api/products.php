<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function product_row_out(array $row): array
{
    $row['image_url'] = $row['image'] ? UPLOAD_URL_PRODUCTS . $row['image'] : null;
    $row['buy_price'] = (float) $row['buy_price'];
    $row['price'] = (float) $row['price'];
    $row['stock'] = (int) $row['stock'];
    $row['low_stock_threshold'] = (int) $row['low_stock_threshold'];
    $row['is_low_stock'] = $row['stock'] <= $row['low_stock_threshold'];
    return $row;
}

/** Whole-inventory summary cards: total stock value, distinct products, total item quantity. */
function product_summary(PDO $pdo): array
{
    $row = $pdo->query('SELECT COUNT(*) AS total_products, COALESCE(SUM(stock),0) AS total_items,
                                COALESCE(SUM(stock * buy_price),0) AS total_value FROM products')->fetch();
    return [
        'total_products' => (int) $row['total_products'],
        'total_items' => (int) $row['total_items'],
        'total_value' => round((float) $row['total_value'], 2),
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
            $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
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
        $sql .= ' ORDER BY p.name ASC';

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

        json_response(['success' => true, 'products' => $rows, 'count' => count($rows), 'summary' => product_summary($pdo)]);
        break;

    // ---------------------------------------------------------
    // NOTE: PHP does not populate $_POST/$_FILES for multipart PUT requests,
    // so updates-with-file use POST + a "_method=PUT" override field.
    case $method === 'POST' && ($_POST['_method'] ?? '') === 'PUT':
        require_non_worker();
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing product id.'], 400);
        }

        $fields = [];
        $params = [];
        foreach (['name' => 's', 'description' => 's', 'buy_price' => 'd', 'price' => 'd', 'stock' => 'd', 'low_stock_threshold' => 'd', 'category_id' => 'd', 'status' => 's'] as $field => $type) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $fields[] = "$field = ?";
                $params[] = $_POST[$field];
            }
        }

        if (!empty($_FILES['image']['name'])) {
            [$imageName, $err] = handle_upload($_FILES['image'], UPLOAD_DIR_PRODUCTS, ALLOWED_IMAGE_EXT, MAX_UPLOAD_BYTES);
            if ($err) {
                json_response(['success' => false, 'message' => $err], 400);
            }
            $fields[] = 'image = ?';
            $params[] = $imageName;
        }

        if (!$fields) {
            json_response(['success' => false, 'message' => 'Nothing to update.'], 400);
        }

        $params[] = $id;
        $stmt = $pdo->prepare('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);

        json_response(['success' => true, 'message' => 'Product updated.']);
        break;

    // ---------------------------------------------------------
    // Add a new stock item (admins/super admins only)
    case $method === 'POST':
        require_non_worker();

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $buyPrice = (float) ($_POST['buy_price'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0);
        $stock = (int) ($_POST['stock'] ?? 0);
        $lowStockThreshold = (int) ($_POST['low_stock_threshold'] ?? 5);
        $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || $price < 0 || $buyPrice < 0) {
            json_response(['success' => false, 'message' => 'Product name and valid prices are required.'], 400);
        }

        $imageName = null;
        if (!empty($_FILES['image']['name'])) {
            [$imageName, $err] = handle_upload($_FILES['image'], UPLOAD_DIR_PRODUCTS, ALLOWED_IMAGE_EXT, MAX_UPLOAD_BYTES);
            if ($err) {
                json_response(['success' => false, 'message' => $err], 400);
            }
        }

        $stmt = $pdo->prepare('INSERT INTO products (category_id, name, description, buy_price, price, stock, low_stock_threshold, image, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$categoryId, $name, $description, $buyPrice, $price, $stock, $lowStockThreshold, $imageName, $status]);

        json_response(['success' => true, 'message' => 'Product added.', 'id' => $pdo->lastInsertId()], 201);
        break;

    // ---------------------------------------------------------
    // Pure JSON update (no new image) - admins/super admins only
    case $method === 'PUT':
        require_non_worker();
        $data = read_json_body();

        $id = (int) ($data['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing product id.'], 400);
        }

        $fields = [];
        $params = [];
        foreach (['name' => 's', 'description' => 's', 'buy_price' => 'd', 'price' => 'd', 'stock' => 'd', 'low_stock_threshold' => 'd', 'category_id' => 'd', 'status' => 's'] as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (!$fields) {
            json_response(['success' => false, 'message' => 'Nothing to update.'], 400);
        }

        $params[] = $id;
        $stmt = $pdo->prepare('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);

        json_response(['success' => true, 'message' => 'Product updated.']);
        break;

    // ---------------------------------------------------------
    case $method === 'DELETE':
        require_non_worker();
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        json_response(['success' => true, 'message' => 'Product deleted.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
