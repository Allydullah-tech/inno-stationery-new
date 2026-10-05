<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

switch ($method) {
    case 'GET':
        $stmt = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = "active") AS product_count
                              FROM categories c ORDER BY c.name ASC');
        json_response(['success' => true, 'categories' => $stmt->fetchAll()]);
        break;

    case 'POST':
        require_admin_auth();
        $body = read_json_body();
        $name = trim($body['name'] ?? '');
        $icon = trim($body['icon'] ?? '');
        if ($name === '') {
            json_response(['success' => false, 'message' => 'Category name is required.'], 400);
        }
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug, icon) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, $icon ?: null]);
        json_response(['success' => true, 'message' => 'Category added.', 'id' => $pdo->lastInsertId()], 201);
        break;

    case 'DELETE':
        require_admin_auth();
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        json_response(['success' => true, 'message' => 'Category deleted.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
