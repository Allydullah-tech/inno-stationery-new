<?php
/** Activity log — who did what and when. Admins only. GET ?from=&to=&entity=&action=&q=&limit= */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
require_non_worker();
$pdo = get_db();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
$where = []; $params = [];
if (!empty($_GET['from']) && valid_date($_GET['from'])) { $where[] = 'created_at >= ?'; $params[] = $_GET['from'] . ' 00:00:00'; }
if (!empty($_GET['to']) && valid_date($_GET['to'])) { $where[] = 'created_at <= ?'; $params[] = $_GET['to'] . ' 23:59:59'; }
if (!empty($_GET['entity'])) { $where[] = 'entity = ?'; $params[] = $_GET['entity']; }
if (!empty($_GET['action'])) { $where[] = 'action = ?'; $params[] = $_GET['action']; }
if (!empty($_GET['q'])) { $where[] = '(description LIKE ? OR admin_name LIKE ?)'; $q = '%' . $_GET['q'] . '%'; array_push($params, $q, $q); }
$limit = max(1, min((int) ($_GET['limit'] ?? 200), 1000));
$st = $pdo->prepare('SELECT * FROM activity_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . $limit);
$st->execute($params);
$ents = $pdo->query('SELECT DISTINCT entity FROM activity_log ORDER BY entity')->fetchAll(PDO::FETCH_COLUMN);
json_response(['success' => true, 'entries' => $st->fetchAll(), 'entities' => $ents]);
