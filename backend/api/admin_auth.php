<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

// Simple login throttling per session
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_attempts_at'] = time();
}

switch (true) {

    // ---------------------------------------------------------
    case $action === 'login' && $method === 'POST':
        if ($_SESSION['login_attempts'] >= 8 && (time() - $_SESSION['login_attempts_at']) < 300) {
            json_response(['success' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);
        }

        $body = read_json_body();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $password === '') {
            json_response(['success' => false, 'message' => 'Username and password are required.'], 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM admins WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $_SESSION['login_attempts']++;
            $_SESSION['login_attempts_at'] = time();
            json_response(['success' => false, 'message' => 'Invalid username or password.'], 401);
        }

        $_SESSION['login_attempts'] = 0;
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'];
        session_regenerate_id(true);

        json_response([
            'success' => true,
            'message' => 'Welcome back, ' . $admin['full_name'] . '!',
            'admin' => [
                'id' => $admin['id'],
                'full_name' => $admin['full_name'],
                'username' => $admin['username'],
                'role' => $admin['role'],
            ],
        ]);
        break;

    // ---------------------------------------------------------
    case $action === 'logout' && $method === 'POST':
        $_SESSION = [];
        session_destroy();
        json_response(['success' => true, 'message' => 'Logged out.']);
        break;

    // ---------------------------------------------------------
    case $action === 'session' && $method === 'GET':
        if (empty($_SESSION['admin_id'])) {
            json_response(['success' => false, 'authenticated' => false]);
        }
        $stmt = $pdo->prepare('SELECT id, full_name, username, email, role FROM admins WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        if (!$admin) {
            json_response(['success' => false, 'authenticated' => false]);
        }
        json_response(['success' => true, 'authenticated' => true, 'admin' => $admin]);
        break;

    // ---------------------------------------------------------
    case $action === 'change_password' && $method === 'POST':
        $admin = require_admin_auth();
        $body = read_json_body();
        $current = $body['current_password'] ?? '';
        $new = $body['new_password'] ?? '';

        if (strlen($new) < 8) {
            json_response(['success' => false, 'message' => 'New password must be at least 8 characters.'], 400);
        }

        $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt->execute([$admin['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            json_response(['success' => false, 'message' => 'Current password is incorrect.'], 401);
        }

        $upd = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);

        json_response(['success' => true, 'message' => 'Password changed successfully.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Unknown action.'], 404);
}
