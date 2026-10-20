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
    $row['paid_from'] = $row['paid_from'] ?? 'cash';
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
            $sql .= ' LIMIT ' . min($limit, 20000);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['success' => true, 'expenses' => array_map('expense_row_out', $stmt->fetchAll())]);
        break;

    // Record an expense (admins AND workers). paid_from tells the cash audit
    // whether the money left the drawer (cash), mobile money (online) or neither (other).
    case 'POST':
        $admin = require_admin_auth();
        $body = read_json_body();

        $description = trim($body['description'] ?? '');
        $category = trim($body['category'] ?? '');
        $amount = round((float) ($body['amount'] ?? 0), 2);
        $expenseDate = !empty($body['expense_date']) ? (string) $body['expense_date'] : today_str();
        $paidFrom = in_array($body['paid_from'] ?? 'cash', ['cash', 'online', 'other'], true) ? $body['paid_from'] : 'cash';

        if ($description === '' || $amount <= 0 || $amount > 1000000000) {
            json_response(['success' => false, 'message' => 'Please provide a description and a valid amount.'], 400);
        }
        if (mb_strlen($description) > 255 || mb_strlen($category) > 80) {
            json_response(['success' => false, 'message' => 'Description (255) or category (80) is too long.'], 400);
        }
        assert_date_allowed($admin, $expenseDate);

        $stmt = $pdo->prepare('INSERT INTO expenses (description, category, amount, recorded_by, expense_date, paid_from) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$description, $category ?: null, $amount, $admin['id'], $expenseDate, $paidFrom]);
        $newId = $pdo->lastInsertId();
        log_activity($pdo, $admin, 'create', 'expense', $newId, number_format($amount) . " — {$description} (paid from {$paidFrom})");

        json_response(['success' => true, 'message' => 'Expense recorded.', 'id' => $newId], 201);
        break;

    // Correct an expense (admins/super admins only)
    case 'PUT':
        $admin = require_non_worker();
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            json_response(['success' => false, 'message' => 'Expense not found.'], 404);
        }
        $description = trim($body['description'] ?? $old['description']);
        $category = trim($body['category'] ?? (string) $old['category']);
        $amount = round((float) ($body['amount'] ?? $old['amount']), 2);
        $date = (string) ($body['expense_date'] ?? $old['expense_date']);
        $paidFrom = in_array($body['paid_from'] ?? $old['paid_from'], ['cash', 'online', 'other'], true) ? ($body['paid_from'] ?? $old['paid_from']) : $old['paid_from'];
        if ($description === '' || $amount <= 0 || mb_strlen($description) > 255 || mb_strlen($category) > 80 || !valid_date($date) || $date > today_str()) {
            json_response(['success' => false, 'message' => 'Please check the description, amount and date.'], 400);
        }
        $pdo->prepare('UPDATE expenses SET description=?, category=?, amount=?, expense_date=?, paid_from=? WHERE id=?')
            ->execute([$description, $category ?: null, $amount, $date, $paidFrom, $id]);
        log_activity($pdo, $admin, 'update', 'expense', $id, 'Expense edited: ' . number_format((float) $old['amount']) . ' → ' . number_format($amount) . ", {$description}");
        json_response(['success' => true, 'message' => 'Expense updated.']);
        break;

    // Delete an expense (admins/super admins only)
    case 'DELETE':
        $admin = require_non_worker();
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $st = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
        $st->execute([$id]);
        if ($old = $st->fetch()) {
            log_activity($pdo, $admin, 'delete', 'expense', $id, 'Expense deleted: ' . number_format((float) $old['amount']) . ' — ' . $old['description']);
        }
        $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
        json_response(['success' => true, 'message' => 'Expense deleted.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
