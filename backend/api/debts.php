<?php
/**
 * Debts / Credit sales management.
 *
 * A "debt" is created automatically by sales.php whenever a sale is
 * recorded with sale_type = 'credit'. This endpoint lets staff:
 *   - view/search/filter outstanding & completed credit sales,
 *   - view a single debt with its full payment history,
 *   - record a payment against a debt (updates amount_paid/balance/status),
 *   - delete a mistaken payment (admins/super admins only).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

const DEBT_PAYMENT_METHODS = ['cash', 'mpesa', 'airtel_money', 'halopesa', 'mixx_by_yas'];

function debt_row_out(array $row): array
{
    $row['original_amount'] = (float) $row['original_amount'];
    $row['amount_paid'] = (float) $row['amount_paid'];
    $row['balance'] = (float) $row['balance'];
    $row['status'] = compute_debt_status($row['balance'], $row['amount_paid'], $row['payment_deadline']);
    return $row;
}

function payment_row_out(array $row): array
{
    $row['amount'] = (float) $row['amount'];
    return $row;
}

/** Recalculate + persist a debt's amount_paid/balance/status from its payment rows. */
function resync_debt(PDO $pdo, int $debtId): array
{
    $debtStmt = $pdo->prepare('SELECT * FROM debts WHERE id = ? LIMIT 1');
    $debtStmt->execute([$debtId]);
    $debt = $debtStmt->fetch();
    if (!$debt) {
        throw new Exception('Debt not found.');
    }

    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM debt_payments WHERE debt_id = ?');
    $sumStmt->execute([$debtId]);
    $amountPaid = round((float) $sumStmt->fetchColumn(), 2);
    $balance = round((float) $debt['original_amount'] - $amountPaid, 2);
    if ($balance < 0) {
        $balance = 0;
    }
    $status = compute_debt_status($balance, $amountPaid, $debt['payment_deadline']);

    $pdo->prepare('UPDATE debts SET amount_paid = ?, balance = ?, status = ? WHERE id = ?')
        ->execute([$amountPaid, $balance, $status, $debtId]);

    return ['amount_paid' => $amountPaid, 'balance' => $balance, 'status' => $status];
}

switch ($method) {

    // Single debt (with payment history) via ?id=, or a filtered/searchable list.
    case 'GET':
        require_admin_auth();

        $id = (int) ($_GET['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare('SELECT d.*, a.full_name AS recorded_by_name FROM debts d
                LEFT JOIN admins a ON a.id = d.recorded_by WHERE d.id = ? LIMIT 1');
            $stmt->execute([$id]);
            $debt = $stmt->fetch();
            if (!$debt) {
                json_response(['success' => false, 'message' => 'Debt record not found.'], 404);
            }
            $payStmt = $pdo->prepare('SELECT dp.*, a.full_name AS recorded_by_name FROM debt_payments dp
                LEFT JOIN admins a ON a.id = dp.recorded_by WHERE dp.debt_id = ? ORDER BY dp.payment_date DESC, dp.created_at DESC');
            $payStmt->execute([$id]);
            $debtOut = debt_row_out($debt);
            $debtOut['payments'] = array_map('payment_row_out', $payStmt->fetchAll());
            json_response(['success' => true, 'debt' => $debtOut]);
        }

        $where = [];
        $params = [];

        if (!empty($_GET['q'])) {
            $where[] = '(d.customer_name LIKE ? OR d.customer_phone LIKE ?)';
            $q = '%' . $_GET['q'] . '%';
            $params[] = $q;
            $params[] = $q;
        }
        if (!empty($_GET['from'])) {
            $where[] = 'd.sale_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 'd.sale_date <= ?';
            $params[] = $_GET['to'];
        }
        $period = $_GET['period'] ?? '';
        if ($period !== '' && $period !== 'all') {
            [$pFrom, $pTo] = resolve_report_range($period, $_GET['from'] ?? null, $_GET['to'] ?? null);
            $where[] = 'd.sale_date BETWEEN ? AND ?';
            $params[] = $pFrom;
            $params[] = $pTo;
        }

        $sql = 'SELECT d.*, a.full_name AS recorded_by_name FROM debts d
                LEFT JOIN admins a ON a.id = d.recorded_by';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY d.sale_date DESC, d.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $debts = array_map('debt_row_out', $stmt->fetchAll());

        // Status filter applied after computing "live" status (overdue is time-based).
        $statusFilter = $_GET['status'] ?? '';
        if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'partial', 'paid', 'overdue'], true)) {
            $debts = array_values(array_filter($debts, fn($d) => $d['status'] === $statusFilter));
        }

        $summary = [
            'total_outstanding' => 0,
            'total_overdue' => 0,
            'total_collected' => 0,
            'count_open' => 0,
        ];
        foreach ($debts as $d) {
            $summary['total_collected'] += $d['amount_paid'];
            if ($d['status'] !== 'paid') {
                $summary['total_outstanding'] += $d['balance'];
                $summary['count_open']++;
                if ($d['status'] === 'overdue') {
                    $summary['total_overdue'] += $d['balance'];
                }
            }
        }
        $summary['total_outstanding'] = round($summary['total_outstanding'], 2);
        $summary['total_overdue'] = round($summary['total_overdue'], 2);
        $summary['total_collected'] = round($summary['total_collected'], 2);

        json_response(['success' => true, 'debts' => $debts, 'summary' => $summary]);
        break;

    // Record a payment against a debt.
    case 'POST':
        $admin = require_admin_auth();
        $body = read_json_body();

        $debtId = (int) ($body['debt_id'] ?? 0);
        $amount = isset($body['amount']) ? (float) $body['amount'] : 0;
        $paymentDate = !empty($body['payment_date']) ? $body['payment_date'] : date('Y-m-d');
        $paymentMethod = $body['payment_method'] ?? 'cash';
        $referenceNo = trim($body['reference_no'] ?? '');
        $notes = trim($body['notes'] ?? '');

        if (!$debtId) {
            json_response(['success' => false, 'message' => 'Missing debt reference.'], 400);
        }
        if ($amount <= 0) {
            json_response(['success' => false, 'message' => 'Please enter a valid payment amount.'], 400);
        }
        if (!in_array($paymentMethod, DEBT_PAYMENT_METHODS, true)) {
            json_response(['success' => false, 'message' => 'Please select a valid payment method.'], 400);
        }
        if (!DateTime::createFromFormat('Y-m-d', $paymentDate)) {
            json_response(['success' => false, 'message' => 'Please provide a valid payment date.'], 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM debts WHERE id = ? LIMIT 1');
        $stmt->execute([$debtId]);
        $debt = $stmt->fetch();
        if (!$debt) {
            json_response(['success' => false, 'message' => 'Debt record not found.'], 404);
        }

        $currentBalance = round((float) $debt['balance'], 2);
        if ($currentBalance <= 0) {
            json_response(['success' => false, 'message' => 'This debt is already fully paid.'], 400);
        }
        // Allow a tiny rounding tolerance, but block genuine overpayment.
        if ($amount > $currentBalance + 0.01) {
            json_response(['success' => false, 'message' => 'Payment amount (' . number_format($amount, 2) . ') exceeds the remaining balance of ' . number_format($currentBalance, 2) . '. Overpayments are not supported yet — please record the exact balance or a partial amount.'], 400);
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO debt_payments
                (debt_id, amount, payment_date, payment_method, reference_no, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$debtId, $amount, $paymentDate, $paymentMethod, $referenceNo ?: null, $notes ?: null, $admin['id']]);
            $paymentId = $pdo->lastInsertId();

            $result = resync_debt($pdo, $debtId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not record payment. Please try again.'], 500);
        }

        json_response([
            'success' => true,
            'message' => $result['status'] === 'paid'
                ? 'Payment recorded. This debt is now fully paid.'
                : 'Payment of ' . number_format($amount, 2) . ' recorded. Remaining balance: ' . number_format($result['balance'], 2) . '.',
            'payment_id' => $paymentId,
            'debt' => $result,
        ], 201);
        break;

    // Delete a mistaken payment (admins/super admins only) — recalculates the debt.
    case 'DELETE':
        require_non_worker();
        $paymentId = (int) ($_GET['payment_id'] ?? 0);
        if (!$paymentId) {
            json_response(['success' => false, 'message' => 'Missing payment id.'], 400);
        }
        $stmt = $pdo->prepare('SELECT * FROM debt_payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            json_response(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM debt_payments WHERE id = ?')->execute([$paymentId]);
            resync_debt($pdo, (int) $payment['debt_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not delete payment.'], 500);
        }

        json_response(['success' => true, 'message' => 'Payment deleted and balance recalculated.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
