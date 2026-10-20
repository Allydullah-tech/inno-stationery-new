<?php
/**
 * Services catalogue (non-stock offerings: printing, binding, design work,
 * lamination, etc.). A service has a category and one or more "types"
 * (e.g. Printing -> B/W A4, Colour A4), each with its own default selling
 * price. Every type is a row in `services`; rows of the same service share
 * a `group_id`. The default price can be overridden on the sale itself.
 *
 *  GET                -> flat list of types; `name` is the display name.
 *  GET ?grouped=1     -> services with their `types` array (Services page).
 *  POST / PUT (JSON)  -> create / update a service and sync its types.
 *  DELETE ?group_id=  -> delete a service with all its types.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function service_row_out(array $row): array
{
    $row['base_name'] = $row['name'];
    $row['variant'] = $row['variant'] ?? '';
    $row['name'] = item_display_name($row['name'], $row['variant']);
    $row['selling_price'] = (float) $row['selling_price'];
    $row['minimum_price'] = isset($row['minimum_price']) && $row['minimum_price'] !== null ? (float) $row['minimum_price'] : null;
    return $row;
}

function read_service_payload(array $data): array
{
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        json_response(['success' => false, 'message' => 'Service name is required.'], 400);
    }
    $typesIn = is_array($data['types'] ?? null) ? $data['types'] : [];
    if (!$typesIn) {
        json_response(['success' => false, 'message' => 'Add at least one type (use a blank type name if the service has only one).'], 400);
    }

    $types = [];
    $seen = [];
    foreach ($typesIn as $t) {
        $variant = trim($t['variant'] ?? '');
        $min = isset($t['minimum_price']) && $t['minimum_price'] !== '' ? (float) $t['minimum_price'] : null;
        $price = isset($t['selling_price']) && $t['selling_price'] !== '' ? (float) $t['selling_price'] : -1;
        if ($price < 0) {
            json_response(['success' => false, 'message' => 'Every type needs a valid selling price.'], 400);
        }
        $key = mb_strtolower($variant);
        if (isset($seen[$key])) {
            json_response(['success' => false, 'message' => 'Each type name must be different.'], 400);
        }
        $seen[$key] = true;
        if ($min !== null && ($min < 0 || ($price >= 0 && $min > $price))) {
            json_response(['success' => false, 'message' => 'The minimum price cannot be negative or above the selling price.'], 400);
        }
        $types[] = ['id' => (int) ($t['id'] ?? 0), 'variant' => $variant, 'selling_price' => $price, 'minimum_price' => $min];
    }

    return [
        'name' => $name,
        'description' => trim($data['description'] ?? ''),
        'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
        'status' => ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'types' => $types,
    ];
}

switch (true) {

    // Any logged-in user (admin or worker) can view services — needed to sell them.
    case $method === 'GET':
        require_admin_auth();

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT s.*, c.name AS category_name FROM services s
                                    LEFT JOIN categories c ON c.id = s.category_id WHERE s.id = ? LIMIT 1');
            $stmt->execute([(int) $_GET['id']]);
            $row = $stmt->fetch();
            if (!$row) {
                json_response(['success' => false, 'message' => 'Service not found.'], 404);
            }
            json_response(['success' => true, 'service' => service_row_out($row)]);
        }

        $where = [];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 's.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['category'])) {
            $where[] = 'c.slug = ?';
            $params[] = $_GET['category'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(s.name LIKE ? OR s.variant LIKE ? OR s.description LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON c.id = s.category_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.name ASC, s.variant ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('service_row_out', $stmt->fetchAll());

        if (!empty($_GET['grouped'])) {
            $groups = [];
            foreach ($rows as $r) {
                $gid = (int) $r['group_id'];
                if (!isset($groups[$gid])) {
                    $groups[$gid] = [
                        'group_id' => $gid,
                        'name' => $r['base_name'],
                        'description' => $r['description'],
                        'category_id' => $r['category_id'],
                        'category_name' => $r['category_name'],
                        'status' => $r['status'],
                        'types' => [],
                    ];
                }
                $groups[$gid]['types'][] = $r;
            }
            $groups = array_values($groups);
            $summary = [
                'total_services' => count($groups),
                'active_services' => count(array_filter($groups, fn($g) => $g['status'] === 'active')),
            ];
            json_response(['success' => true, 'groups' => $groups, 'summary' => $summary]);
        }

        json_response(['success' => true, 'services' => $rows]);
        break;

    // Add a new service with its types (admins/super admins only)
    case $method === 'POST':
        $admin = require_non_worker();
        $p = read_service_payload(read_json_body());

        $pdo->beginTransaction();
        try {
            $groupId = null;
            $ins = $pdo->prepare('INSERT INTO services (category_id, name, variant, description, selling_price, status, group_id, minimum_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($p['types'] as $t) {
                $ins->execute([$p['category_id'], $p['name'], $t['variant'], $p['description'] ?: null, $t['selling_price'], $p['status'], $groupId, $t['minimum_price']]);
                if ($groupId === null) {
                    $groupId = (int) $pdo->lastInsertId();
                    $pdo->prepare('UPDATE services SET group_id = id WHERE id = ?')->execute([$groupId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not save service.'], 500);
        }

        log_activity($pdo, $admin, 'create', 'service', $groupId, 'Service "' . $p['name'] . '" added with ' . count($p['types']) . ' type(s)');
        json_response(['success' => true, 'message' => 'Service added.', 'group_id' => $groupId], 201);
        break;

    // Update a service and sync its types (admins/super admins only)
    case $method === 'PUT':
        $admin = require_non_worker();
        $data = read_json_body();
        $groupId = (int) ($data['group_id'] ?? 0);
        if (!$groupId) {
            json_response(['success' => false, 'message' => 'Missing service id.'], 400);
        }
        $p = read_service_payload($data);

        $existingStmt = $pdo->prepare('SELECT id FROM services WHERE group_id = ?');
        $existingStmt->execute([$groupId]);
        $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$existingIds) {
            json_response(['success' => false, 'message' => 'Service not found.'], 404);
        }

        $pdo->beginTransaction();
        try {
            $keepIds = [];
            $upd = $pdo->prepare('UPDATE services SET category_id = ?, name = ?, variant = ?, description = ?, selling_price = ?, status = ?, minimum_price = ? WHERE id = ? AND group_id = ?');
            $ins = $pdo->prepare('INSERT INTO services (category_id, name, variant, description, selling_price, status, group_id, minimum_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($p['types'] as $t) {
                if ($t['id'] && in_array($t['id'], $existingIds, true)) {
                    $upd->execute([$p['category_id'], $p['name'], $t['variant'], $p['description'] ?: null, $t['selling_price'], $p['status'], $t['minimum_price'], $t['id'], $groupId]);
                    $keepIds[] = $t['id'];
                } else {
                    $ins->execute([$p['category_id'], $p['name'], $t['variant'], $p['description'] ?: null, $t['selling_price'], $p['status'], $groupId, $t['minimum_price']]);
                    $keepIds[] = (int) $pdo->lastInsertId();
                }
            }
            $remove = array_diff($existingIds, $keepIds);
            if ($remove) {
                $in = implode(',', array_map('intval', $remove));
                $pdo->exec("DELETE FROM services WHERE group_id = $groupId AND id IN ($in)");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not update service.'], 500);
        }

        log_activity($pdo, $admin, 'update', 'service', $groupId, 'Service "' . $p['name'] . '" edited (' . count($p['types']) . ' type(s))');
        json_response(['success' => true, 'message' => 'Service updated.']);
        break;

    // Delete a service (admins/super admins only). Past sales keep their
    // own copy of the name/price, so history is unaffected.
    case $method === 'DELETE':
        $admin = require_non_worker();
        $groupId = (int) ($_GET['group_id'] ?? 0);
        if (!$groupId) {
            json_response(['success' => false, 'message' => 'Missing id.'], 400);
        }
        $pdo->prepare('DELETE FROM services WHERE group_id = ?')->execute([$groupId]);
        log_activity($pdo, $admin, 'delete', 'service', $groupId, 'Service group #' . $groupId . ' deleted');
        json_response(['success' => true, 'message' => 'Service deleted.']);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
