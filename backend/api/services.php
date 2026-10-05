<?php
/**
 * Services catalogue (non-stock offerings: printing, binding, design work,
 * lamination, etc.). Selling price here is the *default* price — the
 * actual sale can charge a different (discounted/negotiated) price,
 * recorded on the sale itself without altering this default.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function service_row_out(array $row): array
{
    $row['selling_price'] = (float) $row['selling_price'];
    // Keep the unknown/zero distinction: null stays null, never coerced to 0.
    $row['buying_cost'] = $row['buying_cost'] !== null ? (float) $row['buying_cost'] : null;
    $row['profit'] = $row['buying_cost'] !== null ? round($row['selling_price'] - $row['buying_cost'], 2) : null;
    return $row;
}

switch (true) {

    // Any logged-in user (admin or worker) can view services — needed to sell them.
    case $method === 'GET':
        require_admin_auth();

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $_GET['id']]);
            $row = $stmt->fetch();
            if (!$row) {
                json_response(['success' => false, 'message' => 'Service not found.'], 404);
            }
            json_response(['success' => true, 'service' => service_row_out($row)]);
        }

        $where = [];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(name LIKE ? OR description LIKE ? OR code LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT * FROM services';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('service_row_out', $stmt->fetchAll());

        $summary = [
            'total_services' => count($rows),
            'active_services' => count(array_filter($rows, fn($r) => $r['status'] === 'active')),
        ];

        json_response(['success' => true, 'services' => $rows, 'summary' => $summary]);
        break;

    // Add a new service (admins/super admins only)
    case $method === 'POST':
        require_non_worker();
        $body = read_json_body();

        $name = trim($body['name'] ?? '');
        $code = trim($body['code'] ?? '');
        $description = trim($body['description'] ?? '');
        $sellingPrice = isset($body['selling_price']) ? (float) $body['selling_price'] : -1;
        $buyingCost = isset($body['buying_cost']) && $body['buying_cost'] !== '' && $body['buying_cost'] !== null
            ? (float) $body['buying_cost'] : null;
        $status = ($body['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || $sellingPrice < 0) {
            json_response(['success' => false, 'message' => 'Service name and a valid selling price are required.'], 400);
        }
        if ($buyingCost !== null && $buyingCost < 0) {
            json_response(['success' => false, 'message' => 'Buying cost cannot be negative.'], 400);
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO services (name, code, description, buying_cost, selling_price, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $code ?: null, $description ?: null, $buyingCost, $sellingPrice, $status]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_response(['success' => false, 'message' => 'That service code is already in use.'], 400);
            }
            json_response(['success' => false, 'message' => 'Could not save service.'], 500);
        }

        json_response(['success' => true, 'message' => 'Service added.', 'id' => $pdo->lastInsertId()], 201);
        break;

    // Update a service (admins/super admins only)
    case $method === 'PUT':
        require_non_worker();
        $data = read_json_body();

        $id = (int) ($data['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing service id.'], 400);
        }

        if (isset($data['selling_price']) && (float) $data['selling_price'] < 0) {
            json_response(['success' => false, 'message' => 'Selling price cannot be negative.'], 400);
        }
        if (isset($data['buying_cost']) && $data['buying_cost'] !== '' && $data['buying_cost'] !== null && (float) $data['buying_cost'] < 0) {
            json_response(['success' => false, 'message' => 'Buying cost cannot be negative.'], 400);
        }

        $fields = [];
        $params = [];
        foreach (['name', 'code', 'description', 'selling_price', 'status'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        // buying_cost is special: an explicit null/'' clears it back to "unknown".
        if (array_key_exists('buying_cost', $data)) {
            $fields[] = 'buying_cost = ?';
            $params[] = ($data['buying_cost'] === '' || $data['buying_cost'] === null) ? null : (float) $data['buying_cost'];
        }

        if (!$fields) {
            json_response(['success' => false, 'message' => 'Nothing to update.'], 400);
        }

        $params[] = $id;
        try {
            $pdo->prepare('UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_response(['success' => false, 'message' => 'That service code is already in use.'], 400);
            }
            json_response(['success' => false, 'message' => 'Could not update service.'], 500);
        }

        json_response(['success' => true, 'message' => 'Service updated.']);
        break;

    // Delete a service (admins/super admins only). Past sales keep their
    // own copy of the name/price, so history is unaffected.
    case $method === 'DELETE':
        require_non_worker();
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
        json_response(['success' => true, 'message' => 'Service deleted.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
