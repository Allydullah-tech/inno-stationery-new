<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function expense_row_out(array $row): array
{
    $row['amount'] = (float) $row['amount'];
    return $row;
}

switch ($method) {

    case 'GET':
        require_admin_auth();
        $where = [];
        $params = [];
        if (!empty($_GET['from'])) {
            $where[] = 'e.expense_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 'e.expense_date <= ?';
            $params[] = $_GET['to'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(e.description LIKE ? OR e.category LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql = 'SELECT e.*, a.full_name AS recorded_by_name FROM expenses e
                LEFT JOIN admins a ON a.id = e.recorded_by';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.expense_date DESC, e.created_at DESC';

        $limit = (int) ($_GET['limit'] ?? 0);
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['success' => true, 'expenses' => array_map('expense_row_out', $stmt->fetchAll())]);
        break;

    // Record an expense (admins AND workers)
    case 'POST':
        $admin = require_admin_auth();
        $body = read_json_body();

        $description = trim($body['description'] ?? '');
        $category = trim($body['category'] ?? '');
        $amount = (float) ($body['amount'] ?? 0);
        $expenseDate = !empty($body['expense_date']) ? $body['expense_date'] : date('Y-m-d');

        if ($description === '' || $amount <= 0) {
            json_response(['success' => false, 'message' => 'Please provide a description and a valid amount.'], 400);
        }

        $stmt = $pdo->prepare('INSERT INTO expenses (description, category, amount, recorded_by, expense_date) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$description, $category ?: null, $amount, $admin['id'], $expenseDate]);

        json_response(['success' => true, 'message' => 'Expense recorded.', 'id' => $pdo->lastInsertId()], 201);
        break;

    // Delete an expense (admins/super admins only)
    case 'DELETE':
        require_non_worker();
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
        json_response(['success' => true, 'message' => 'Expense deleted.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
