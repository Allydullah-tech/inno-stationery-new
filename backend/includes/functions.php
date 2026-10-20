<?php
/**
 * INNO's STATIONERY (Internal System) - Shared helper functions
 */

/** Send a JSON response and stop execution. */
function json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** Send standard CORS + JSON headers. Call at the top of every api file. */
function send_api_headers(): void
{
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Read JSON body of a request into an associative array. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Basic string sanitizer for output/storage. */
function clean_str($value): string
{
    return trim(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
}

/** Generate a short reference code, e.g. PUR-7F3K2Q */
function generate_reference_code(string $prefix = 'REF'): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $prefix . '-' . $code;
}

/** Require the current session to belong to a logged-in admin/worker. Halts with 401 otherwise. */
function require_admin_auth(): array
{
    if (empty($_SESSION['admin_id'])) {
        json_response(['success' => false, 'message' => 'Not authenticated. Please log in.'], 401);
    }
    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'] ?? '',
        'role' => $_SESSION['admin_role'] ?? 'worker',
    ];
}

/**
 * Require the logged-in user to be an admin or super_admin (NOT a worker).
 * Workers can record sales/expenses/damages and view stock, but cannot
 * manage products, purchases, reports, admins, or settings.
 */
function require_non_worker(): array
{
    $admin = require_admin_auth();
    if ($admin['role'] === 'worker') {
        json_response(['success' => false, 'message' => 'Workers do not have access to this action.'], 403);
    }
    return $admin;
}

/** Require the logged-in admin to be a super_admin. */
function require_super_admin(): array
{
    $admin = require_admin_auth();
    if ($admin['role'] !== 'super_admin') {
        json_response(['success' => false, 'message' => 'Only a super admin can perform this action.'], 403);
    }
    return $admin;
}

/** Fetch a setting value by key, with optional default. */
function get_setting(string $key, string $default = ''): string
{
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

/** Handle a single uploaded file: validate + move it. Returns [storedName, error]. */
function handle_upload(array $file, string $destDir, array $allowedExt, int $maxBytes): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed. Please try again.'];
    }
    if ($file['size'] > $maxBytes) {
        return [null, 'File is too large. Maximum size is ' . round($maxBytes / 1024 / 1024) . 'MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return [null, 'File type not allowed. Allowed types: ' . implode(', ', $allowedExt)];
    }
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $storedName = uniqid('f_', true) . '.' . $ext;
    $destPath = $destDir . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return [null, 'Could not save uploaded file.'];
    }
    return [$storedName, null];
}

/**
 * Resolve a [start_date, end_date] pair (Y-m-d strings) from a period request.
 * $period: 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom'
 * For 'custom', $from/$to are used directly (validated as dates).
 */
function resolve_report_range(string $period, ?string $from, ?string $to): array
{
    $today = new DateTime('today');
    switch ($period) {
        case 'daily':
            return [$today->format('Y-m-d'), $today->format('Y-m-d')];
        case 'weekly':
            $start = (clone $today)->modify('monday this week');
            return [$start->format('Y-m-d'), $today->format('Y-m-d')];
        case 'monthly':
            $start = (clone $today)->modify('first day of this month');
            return [$start->format('Y-m-d'), $today->format('Y-m-d')];
        case 'yearly':
            $start = new DateTime($today->format('Y') . '-01-01');
            return [$start->format('Y-m-d'), $today->format('Y-m-d')];
        case 'custom':
            $fromValid = $from && DateTime::createFromFormat('Y-m-d', $from);
            $toValid = $to && DateTime::createFromFormat('Y-m-d', $to);
            if (!$fromValid || !$toValid) {
                json_response(['success' => false, 'message' => 'Please provide valid from/to dates (YYYY-MM-DD).'], 400);
            }
            return [$from, $to];
        default:
            json_response(['success' => false, 'message' => 'Invalid report period.'], 400);
    }
}

/**
 * Work out a debt's current status from its numbers + deadline.
 * Recomputed every time it's read, so a debt automatically flips to
 * "overdue" once its deadline passes — not just when a payment happens.
 */
function compute_debt_status(float $balance, float $amountPaid, string $paymentDeadline): string
{
    if ($balance <= 0.004) {
        return 'paid';
    }
    $today = date('Y-m-d');
    if ($paymentDeadline < $today) {
        return 'overdue';
    }
    if ($amountPaid > 0) {
        return 'partial';
    }
    return 'pending';
}

/** Output the given rows as a downloadable CSV (opens natively in Excel). */
function output_csv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/** "Pen" + "Blue" => "Pen - Blue"; no type => just the name. */
function item_display_name(string $name, ?string $variant): string
{
    $variant = trim((string) $variant);
    return $variant === '' ? $name : $name . ' - ' . $variant;
}


/* ==========================================================================
   v3 helpers (ported from the edesk system)
   ========================================================================== */

/** True for a real calendar date in Y-m-d form. */
function valid_date($d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

/** Today's date in the shop's timezone (set in config.php). */
function today_str(): string
{
    return date('Y-m-d');
}

/** True when the logged-in user is a plain worker. */
function is_worker(array $admin): bool
{
    return ($admin['role'] ?? 'worker') === 'worker';
}

/**
 * Workers may only record / change things dated today.
 * Admins and super admins may use any date up to today.
 */
function assert_date_allowed(array $admin, string $date, bool $allowFuture = false): void
{
    if (!valid_date($date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid date (YYYY-MM-DD).'], 400);
    }
    if (!$allowFuture && $date > today_str()) {
        json_response(['success' => false, 'message' => 'Future dates are not allowed here.'], 400);
    }
    if (is_worker($admin) && $date !== today_str()) {
        json_response(['success' => false, 'message' => 'Workers can only use today\'s date. Ask an admin for back-dated entries.'], 403);
    }
}

/** Normalise a phone number so 0712..., +255712... and 255712... match. */
function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 12 && strpos($digits, '255') === 0) {
        $digits = '0' . substr($digits, 3);
    } elseif (strlen($digits) === 9) {
        $digits = '0' . $digits;
    }
    return $digits;
}

/** Make a CSV cell safe against spreadsheet formula injection. */
function csv_safe($v)
{
    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;
    }
    return $v;
}

/**
 * Write one row to the activity log. Never throws — logging must not
 * break the action being logged.
 */
function log_activity(PDO $pdo, ?array $admin, string $action, string $entity, $entityId = null, string $description = ''): void
{
    try {
        $name = null;
        if ($admin && !empty($admin['id'])) {
            $st = $pdo->prepare('SELECT full_name FROM admins WHERE id = ?');
            $st->execute([$admin['id']]);
            $name = $st->fetchColumn() ?: ($admin['username'] ?? null);
        }
        $pdo->prepare('INSERT INTO activity_log (admin_id, admin_name, action, entity, entity_id, description, ip_address) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $admin['id'] ?? null,
                $name,
                $action,
                $entity,
                $entityId !== null ? (string) $entityId : null,
                mb_substr($description, 0, 500),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) {
        // ignore
    }
}

/** Cash-sale payment methods that count as "money in the drawer". */
function is_cash_method(?string $m): bool
{
    return $m === 'cash_in_hand' || $m === 'cash';
}

/** Find or create a customer by phone; returns customer row (or null if no phone). */
function upsert_customer(PDO $pdo, string $name, string $phone): ?array
{
    $phone = normalize_phone($phone);
    $name = trim($name);
    if ($phone === '' || $name === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM customers WHERE phone = ? LIMIT 1');
    $st->execute([$phone]);
    $c = $st->fetch();
    if ($c) {
        return $c;
    }
    $pdo->prepare('INSERT INTO customers (name, phone) VALUES (?, ?)')->execute([$name, $phone]);
    $st->execute([$phone]);
    return $st->fetch() ?: null;
}

/**
 * Re-evaluate a customer's risk. A customer with N or more overdue debts
 * (N = setting restrict_after_overdue, default 3) is restricted from new
 * credit until an admin lifts it. A lifted restriction is not re-applied
 * until a NEW overdue debt appears after the lift.
 */
function refresh_customer_risk(PDO $pdo, int $customerId): void
{
    $limit = max(1, (int) get_setting('restrict_after_overdue', '3'));
    $c = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $c->execute([$customerId]);
    $cust = $c->fetch();
    if (!$cust) {
        return;
    }
    $since = $cust['lifted_at'] ?: '1970-01-01 00:00:00';
    $st = $pdo->prepare('SELECT COUNT(*) FROM debts WHERE customer_id = ? AND balance > 0.004 AND payment_deadline < ? AND payment_deadline >= DATE(?)');
    $st->execute([$customerId, today_str(), $since]);
    $overdue = (int) $st->fetchColumn();
    if ($cust['status'] === 'active' && $overdue >= $limit) {
        $pdo->prepare("UPDATE customers SET status='restricted', restricted_at=NOW(), restriction_reason=? WHERE id=?")
            ->execute([$overdue . ' overdue debts — credit blocked until an admin lifts it', $customerId]);
    }
}

/* ---- Shop time zone: PHP and MySQL must agree on what "today" and "now" are ---- */
date_default_timezone_set('Africa/Dar_es_Salaam');
if (function_exists('get_db')) {
    try {
        get_db()->exec("SET time_zone = '" . date('P') . "'");
    } catch (Throwable $e) {
        // keep going with the server default
    }
}
