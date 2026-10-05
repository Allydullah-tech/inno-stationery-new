<?php
/**
 * Password recovery flow using security questions (no email server required).
 *
 * Step 1: ?action=get_questions   POST {username}      -> returns the two questions
 * Step 2: ?action=verify_answers  POST {username, answer_1, answer_2} -> returns a short-lived reset token
 * Step 3: ?action=reset_password  POST {username, token, new_password} -> updates password
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function log_reset_attempt(PDO $pdo, int $adminId, bool $success): void
{
    $stmt = $pdo->prepare('INSERT INTO password_reset_log (admin_id, ip_address, success) VALUES (?, ?, ?)');
    $stmt->execute([$adminId, $_SERVER['REMOTE_ADDR'] ?? '', $success ? 1 : 0]);
}

function too_many_recent_failures(PDO $pdo, int $adminId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_reset_log WHERE admin_id = ? AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)');
    $stmt->execute([$adminId]);
    return (int) $stmt->fetchColumn() >= 6;
}

switch (true) {

    // ---------------------------------------------------------
    case $action === 'get_questions' && $method === 'POST':
        $body = read_json_body();
        $username = trim($body['username'] ?? '');

        $stmt = $pdo->prepare('SELECT id, security_question_1, security_question_2 FROM admins WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch();

        // Always respond generically to avoid leaking which usernames exist.
        if (!$admin) {
            json_response(['success' => false, 'message' => 'No account found with that username/email.'], 404);
        }

        json_response([
            'success' => true,
            'question_1' => $admin['security_question_1'],
            'question_2' => $admin['security_question_2'],
        ]);
        break;

    // ---------------------------------------------------------
    case $action === 'verify_answers' && $method === 'POST':
        $body = read_json_body();
        $username = trim($body['username'] ?? '');
        $answer1 = strtolower(trim($body['answer_1'] ?? ''));
        $answer2 = strtolower(trim($body['answer_2'] ?? ''));

        $stmt = $pdo->prepare('SELECT * FROM admins WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch();

        if (!$admin) {
            json_response(['success' => false, 'message' => 'Account not found.'], 404);
        }

        if (too_many_recent_failures($pdo, $admin['id'])) {
            json_response(['success' => false, 'message' => 'Too many failed attempts. Please try again later.'], 429);
        }

        $ok = password_verify($answer1, $admin['security_answer_1']) && password_verify($answer2, $admin['security_answer_2']);
        log_reset_attempt($pdo, $admin['id'], $ok);

        if (!$ok) {
            json_response(['success' => false, 'message' => 'One or both answers are incorrect.'], 401);
        }

        // Short-lived token (15 min), stored server-side in the PHP session.
        $token = bin2hex(random_bytes(24));
        $_SESSION['pw_reset'] = [
            'admin_id' => $admin['id'],
            'token' => $token,
            'expires' => time() + 900,
        ];

        json_response(['success' => true, 'token' => $token]);
        break;

    // ---------------------------------------------------------
    case $action === 'reset_password' && $method === 'POST':
        $body = read_json_body();
        $token = $body['token'] ?? '';
        $newPassword = $body['new_password'] ?? '';

        $sess = $_SESSION['pw_reset'] ?? null;

        if (!$sess || !hash_equals($sess['token'], $token) || time() > $sess['expires']) {
            json_response(['success' => false, 'message' => 'Reset session expired. Please start again.'], 401);
        }

        if (strlen($newPassword) < 8) {
            json_response(['success' => false, 'message' => 'Password must be at least 8 characters.'], 400);
        }

        $upd = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($newPassword, PASSWORD_DEFAULT), $sess['admin_id']]);

        unset($_SESSION['pw_reset']);

        json_response(['success' => true, 'message' => 'Password reset successfully. You can now log in.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Unknown action.'], 404);
}
