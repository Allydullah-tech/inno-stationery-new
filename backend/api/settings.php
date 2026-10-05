<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

switch ($method) {
    // Any logged-in user (admin or worker) can view settings — none of it is sensitive
    // now that there's no customer-facing payment info to protect.
    case 'GET':
        require_admin_auth();
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        json_response(['success' => true, 'settings' => $out]);
        break;

    // Only admins/super admins can change settings.
    case 'PUT':
        require_non_worker();
        $body = read_json_body();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($body as $key => $value) {
            $stmt->execute([clean_str($key), (string) $value]);
        }
        $pdo->commit();
        json_response(['success' => true, 'message' => 'Settings updated.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
