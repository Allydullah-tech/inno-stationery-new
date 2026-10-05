<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

switch ($method) {

    // List all admins/workers (admins and super admins only — not workers)
    case 'GET':
        require_non_worker();
        $stmt = $pdo->query('SELECT id, full_name, username, email, role, is_active, created_at FROM admins ORDER BY created_at ASC');
        json_response(['success' => true, 'admins' => $stmt->fetchAll()]);
        break;

    // Add a new admin OR worker (admins and super admins only)
    case 'POST':
        require_non_worker();
        $body = read_json_body();

        $fullName = trim($body['full_name'] ?? '');
        $username = trim($body['username'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $role = in_array($body['role'] ?? '', ['super_admin', 'admin', 'worker'], true) ? $body['role'] : 'worker';
        $sq1 = trim($body['security_question_1'] ?? '');
        $sa1 = trim($body['security_answer_1'] ?? '');
        $sq2 = trim($body['security_question_2'] ?? '');
        $sa2 = trim($body['security_answer_2'] ?? '');

        if ($fullName === '' || $username === '' || $email === '' || strlen($password) < 8) {
            json_response(['success' => false, 'message' => 'Please provide full name, username, email and a password of at least 8 characters.'], 400);
        }
        if ($sq1 === '' || $sa1 === '' || $sq2 === '' || $sa2 === '' || $sq1 === $sq2) {
            json_response(['success' => false, 'message' => 'Please provide two different security questions with answers.'], 400);
        }

        $dupe = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?');
        $dupe->execute([$username, $email]);
        if ((int) $dupe->fetchColumn() > 0) {
            json_response(['success' => false, 'message' => 'Username or email already in use.'], 409);
        }

        $stmt = $pdo->prepare('INSERT INTO admins
            (full_name, username, email, password_hash, role, security_question_1, security_answer_1, security_question_2, security_answer_2)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $fullName, $username, $email,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $sq1, password_hash(strtolower($sa1), PASSWORD_DEFAULT),
            $sq2, password_hash(strtolower($sa2), PASSWORD_DEFAULT),
        ]);

        json_response(['success' => true, 'message' => ($role === 'worker' ? 'New worker' : 'New admin') . ' account created.'], 201);
        break;

    // Activate / deactivate an account (super admin only, cannot deactivate self)
    case 'PUT':
        $current = require_super_admin();
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $isActive = isset($body['is_active']) ? (int) (bool) $body['is_active'] : null;

        if (!$id || $isActive === null) {
            json_response(['success' => false, 'message' => 'Missing id or is_active.'], 400);
        }
        if ($id === (int) $current['id'] && $isActive === 0) {
            json_response(['success' => false, 'message' => 'You cannot deactivate your own account.'], 400);
        }

        $stmt = $pdo->prepare('UPDATE admins SET is_active = ? WHERE id = ?');
        $stmt->execute([$isActive, $id]);

        json_response(['success' => true, 'message' => 'Account status updated.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
