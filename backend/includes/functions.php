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
