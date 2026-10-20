<?php
/**
 * Purchases (adding products to stock).
 *
 * Two ways to add stock:
 *   1. Manual: POST JSON with a list of items.
 *   2. CSV import: POST multipart/form-data with a "file" field, first in
 *      PREVIEW mode (mode=preview — parses and validates only, no DB
 *      writes) so the user can review/edit every row, then confirmed via
 *      a normal JSON POST (same as manual entry) with source=excel.
 *      Expected CSV columns (header row required):
 *        product_name, quantity, buy_price, new_sell_price
 *      - quantity: total number of units received.
 *      - buy_price: cost per unit (same "Buy price" field used on the
 *        Stock page). If your supplier quoted a price per dozen/box,
 *        divide it by how many units are in it first.
 *      - new_sell_price: only needed for a brand-new product (one that
 *        doesn't exist yet) — existing products keep their current
 *        selling price unless you explicitly override it.
 *      Matches products by exact name, or creates a new one if none exists.
 *
 * NOTE: true .xlsx parsing needs a third-party library (e.g. PhpSpreadsheet)
 * which isn't installed by default on most shared hosting. CSV works
 * identically for this purpose and opens/saves natively in Excel
 * (File → Save As → CSV), so that's what this endpoint accepts.
 *
 * Internally, line items are still stored in the purchase_items table
 * using its original pack_size/pack_price/packs_received columns (no
 * migration needed) — pack_size is simply always 1 now, so pack_price =
 * buy_price and packs_received = quantity.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

send_api_headers();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();

function purchase_row_out(PDO $pdo, array $row): array
{
    $items = $pdo->prepare('SELECT * FROM purchase_items WHERE purchase_id = ?');
    $items->execute([$row['id']]);
    $row['items'] = array_map(function ($i) {
        // Present with the simplified quantity/buy_price vocabulary,
        // regardless of the legacy pack-based column names underneath.
        $i['buy_price'] = (float) $i['unit_buy_price'];
        $i['quantity'] = (int) $i['quantity_units'];
        $i['unit_buy_price'] = (float) $i['unit_buy_price'];
        $i['quantity_units'] = (int) $i['quantity_units'];
        $i['new_sell_price'] = $i['new_sell_price'] !== null ? (float) $i['new_sell_price'] : null;
        unset($i['pack_price'], $i['packs_received'], $i['pack_size']);
        return $i;
    }, $items->fetchAll());
    $row['total_cost'] = (float) $row['total_cost'];
    return $row;
}

/**
 * CSV header names vary a lot between people's spreadsheets. Recognize
 * common alternatives instead of requiring one exact set of column
 * names — e.g. "Product" or "Item" for product_name, "Amount",
 * "Unit Price" or "Cost" for buy_price.
 */
function resolve_purchase_csv_header(array $header): array
{
    $aliases = [
        'product_name' => ['product_name', 'product', 'item', 'item_name', 'name', 'description'],
        'variant' => ['variant', 'type', 'types', 'variety'],
        'quantity' => ['quantity', 'qty', 'quantity_received', 'units', 'units_received'],
        'buy_price' => ['buy_price', 'amount', 'unit_price', 'unit_cost', 'cost', 'price_per_unit', 'buying_price', 'unit_amount'],
        'new_sell_price' => ['new_sell_price', 'sell_price', 'selling_price', 'sale_price'],
    ];
    $map = [];
    foreach ($aliases as $canonical => $candidates) {
        foreach ($candidates as $c) {
            if (in_array($c, $header, true)) {
                $map[$canonical] = $c;
                break;
            }
        }
    }
    return $map;
}

/** Re-key one parsed CSV row from whatever header names it used to the canonical field names. */
function map_csv_row_to_canonical(array $row, array $colMap): array
{
    $get = function ($canonical) use ($row, $colMap) {
        if (isset($colMap[$canonical]) && isset($row[$colMap[$canonical]])) {
            return $row[$colMap[$canonical]];
        }
        return $row[$canonical] ?? null;
    };
    return [
        'product_name' => $get('product_name'),
        'variant' => $get('variant'),
        'quantity' => $get('quantity'),
        'buy_price' => $get('buy_price'),
        'new_sell_price' => $get('new_sell_price'),
        // legacy pack-based columns, still honored as a fallback
        'pack_size' => $row['pack_size'] ?? null,
        'pack_price' => $row['pack_price'] ?? null,
        'packs_received' => $row['packs_received'] ?? null,
    ];
}

/**
 * Parse one raw CSV/manual row into normalized fields, without touching
 * the database. Shared by preview mode and the CSV column-mapping step.
 */
function normalize_purchase_row(array $row): array
{
    $quantity = $row['quantity'] ?? (($row['pack_size'] ?? 1) * ($row['packs_received'] ?? 0));
    $buyPrice = $row['buy_price'] ?? (isset($row['pack_price'], $row['pack_size']) && $row['pack_size'] > 0 ? $row['pack_price'] / $row['pack_size'] : ($row['pack_price'] ?? 0));
    return [
        'product_name' => trim($row['product_name'] ?? ''),
        'variant' => trim($row['variant'] ?? ''),
        'quantity' => (float) $quantity,
        'buy_price' => (float) $buyPrice,
        'new_sell_price' => isset($row['new_sell_price']) && $row['new_sell_price'] !== '' ? (float) $row['new_sell_price'] : null,
    ];
}

/**
 * Look up an existing product type by product name + type name.
 * If no type is given, a legacy display name like "Pen - Blue" also matches.
 */
function lookup_existing_product(PDO $pdo, string $productName, string $variant = ''): ?array
{
    $stmt = $pdo->prepare('SELECT id, price, buy_price FROM products WHERE name = ? AND variant = ? LIMIT 1');
    $stmt->execute([$productName, $variant]);
    $row = $stmt->fetch();
    if (!$row && $variant === '') {
        $stmt = $pdo->prepare("SELECT id, price, buy_price FROM products
                               WHERE CONCAT_WS(' - ', name, NULLIF(variant, '')) = ? LIMIT 1");
        $stmt->execute([$productName]);
        $row = $stmt->fetch();
    }
    return $row ?: null;
}

/** Make sure products has the unit column (added once, silently). */
function ensure_product_unit_column(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'unit'")->fetch()) {
            $pdo->exec("ALTER TABLE products ADD COLUMN unit VARCHAR(30) NOT NULL DEFAULT 'pcs'");
        }
    } catch (Throwable $e) {
        error_log('unit column: ' . $e->getMessage());
    }
}

/** Apply one purchase line item: update/create product stock + price. */
function apply_purchase_item(PDO $pdo, array $item): array
{
    $productName = trim($item['product_name'] ?? '');
    $variant = trim($item['variant'] ?? '');
    $quantity = (int) round((float) ($item['quantity'] ?? 0));
    $buyPrice = (float) ($item['buy_price'] ?? 0);
    $newSellPrice = isset($item['new_sell_price']) && $item['new_sell_price'] !== '' ? (float) $item['new_sell_price'] : null;
    $productId = !empty($item['product_id']) ? (int) $item['product_id'] : null;
    $minPrice = isset($item['minimum_price']) && $item['minimum_price'] !== '' && $item['minimum_price'] !== null ? (float) $item['minimum_price'] : null;
    $unit = mb_substr(trim((string) ($item['unit'] ?? '')), 0, 30);
    ensure_product_unit_column($pdo);

    if ($productName === '' || $buyPrice <= 0 || $quantity <= 0) {
        return [null, 'Each item needs a product name, buy price, and quantity.'];
    }

    $unitBuyPrice = $buyPrice;
    $quantityUnits = $quantity;

    // Try to match an existing product by id, or by exact name if no id given.
    if (!$productId) {
        $existing = lookup_existing_product($pdo, $productName, $variant);
        if ($existing) {
            $productId = (int) $existing['id'];
        }
    }

    if ($productId) {
        $fields = 'stock = stock + ?, buy_price = ?';
        $params = [$quantityUnits, $unitBuyPrice];
        if ($newSellPrice !== null) {
            $fields .= ', price = ?';
            $params[] = $newSellPrice;
        }
        if ($minPrice !== null) {
            $curPrice = $newSellPrice ?? (float) ($existing['price'] ?? $pdo->query('SELECT price FROM products WHERE id = ' . (int) $productId)->fetchColumn());
            if ($minPrice > $curPrice) {
                return [null, "The minimum price of '$productName' cannot be higher than its selling price."];
            }
            $fields .= ', minimum_price = ?';
            $params[] = $minPrice;
        }
        if ($unit !== '') {
            $fields .= ', unit = ?';
            $params[] = $unit;
        }
        $params[] = $productId;
        $pdo->prepare("UPDATE products SET $fields WHERE id = ?")->execute($params);
    } else {
        // Brand-new product — a selling price MUST be provided explicitly
        // rather than guessed with an automatic markup.
        if ($newSellPrice === null) {
            return [null, "'$productName' is a new product — please provide a selling price for it."];
        }
        // A new type of an existing product joins that product's group and
        // inherits its category/status/description; otherwise it starts a new product.
        $siblingStmt = $pdo->prepare('SELECT group_id, category_id, description, status FROM products WHERE name = ? ORDER BY id LIMIT 1');
        $siblingStmt->execute([$productName]);
        $sibling = $siblingStmt->fetch();

        if ($minPrice !== null && $minPrice > $newSellPrice) {
            return [null, "The minimum price of '$productName' cannot be higher than its selling price."];
        }
        $insert = $pdo->prepare('INSERT INTO products (group_id, category_id, name, variant, description, status, buy_price, price, stock, minimum_price, unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            $sibling['group_id'] ?? null, $sibling['category_id'] ?? null, $productName, $variant,
            $sibling['description'] ?? null, $sibling['status'] ?? 'active', $unitBuyPrice, $newSellPrice, $quantityUnits,
            $minPrice, $unit !== '' ? $unit : 'pcs',
        ]);
        $productId = (int) $pdo->lastInsertId();
        if (!$sibling) {
            $pdo->prepare('UPDATE products SET group_id = id WHERE id = ?')->execute([$productId]);
        }
    }

    // Internal storage keeps the original pack_size/pack_price/packs_received
    // columns (pack_size fixed at 1) so no database migration is needed.
    return [[
        'product_id' => $productId,
        'product_name' => item_display_name($productName, $variant),
        'pack_size' => 1,
        'pack_price' => $buyPrice,
        'packs_received' => $quantity,
        'unit_buy_price' => $unitBuyPrice,
        'quantity_units' => $quantityUnits,
        'new_sell_price' => $newSellPrice,
    ], null];
}

switch (true) {

    case $method === 'GET':
        require_non_worker();
        $where = [];
        $params = [];

        if (!empty($_GET['source']) && in_array($_GET['source'], ['manual', 'excel'], true)) {
            $where[] = 'p.source = ?';
            $params[] = $_GET['source'];
        }

        $period = $_GET['period'] ?? '';
        if ($period !== '' && $period !== 'all') {
            [$from, $to] = resolve_report_range($period, $_GET['from'] ?? null, $_GET['to'] ?? null);
            $where[] = 'p.purchase_date BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        }

        $sql = 'SELECT p.*, a.full_name AS recorded_by_name FROM purchases p LEFT JOIN admins a ON a.id = p.recorded_by';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.purchase_date DESC, p.created_at DESC';

        $limit = (int) ($_GET['limit'] ?? 0);
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $purchases = array_map(fn($r) => purchase_row_out($pdo, $r), $rows);

        // Summary reflects exactly the filtered set above (source + date range).
        $totalCost = 0;
        $distinctProducts = [];
        $totalItems = 0;
        foreach ($purchases as $p) {
            $totalCost += $p['total_cost'];
            foreach ($p['items'] as $item) {
                if ($item['product_id']) {
                    $distinctProducts[$item['product_id']] = true;
                } else {
                    $distinctProducts['name:' . $item['product_name']] = true;
                }
                $totalItems += $item['quantity_units'];
            }
        }
        $summary = [
            'total_cost' => round($totalCost, 2),
            'total_products' => count($distinctProducts),
            'total_items' => $totalItems,
            'purchase_count' => count($purchases),
        ];

        json_response(['success' => true, 'purchases' => $purchases, 'summary' => $summary]);
        break;

    // Three ways to hit POST:
    //   a) multipart/form-data + file + mode=preview  -> parse & validate only, no DB writes
    //   b) JSON body with items[]                     -> apply (manual entry, or a confirmed CSV review)
    // admins/super admins only.
    case $method === 'POST':
        $admin = require_non_worker();

        $isCsv = !empty($_FILES['file']['name']);

        // ---- (a) CSV preview: parse + resolve against existing products, apply nothing ----
        if ($isCsv && ($_POST['mode'] ?? '') === 'preview') {
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                json_response(['success' => false, 'message' => 'Please upload a .csv file. Export your Excel sheet as CSV first (File → Save As → CSV).'], 400);
            }
            $handle = fopen($_FILES['file']['tmp_name'], 'r');
            if (!$handle) {
                json_response(['success' => false, 'message' => 'Could not read the uploaded file.'], 400);
            }
            $header = fgetcsv($handle);
            if (!$header) {
                json_response(['success' => false, 'message' => 'CSV file appears to be empty.'], 400);
            }
            $header = array_map(fn($h) => strtolower(trim($h)), $header);
            $colMap = resolve_purchase_csv_header($header);

            $preview = [];
            while (($line = fgetcsv($handle)) !== false) {
                if (count(array_filter($line, fn($v) => trim((string) $v) !== '')) === 0) {
                    continue; // skip blank lines
                }
                $row = array_combine($header, array_pad($line, count($header), null));
                $n = normalize_purchase_row(map_csv_row_to_canonical($row, $colMap));

                $existing = $n['product_name'] !== '' ? lookup_existing_product($pdo, $n['product_name'], $n['variant']) : null;
                $rowError = null;
                if ($n['product_name'] === '') {
                    $rowError = 'Missing product name.';
                } elseif ($n['quantity'] <= 0) {
                    $rowError = 'Missing or invalid quantity.';
                } elseif ($n['buy_price'] <= 0) {
                    $rowError = 'Missing or invalid buy price.';
                } elseif (!$existing && $n['new_sell_price'] === null) {
                    $rowError = 'New product — needs a selling price.';
                }

                $preview[] = [
                    'product_id' => $existing['id'] ?? null,
                    'product_name' => $n['product_name'],
                    'variant' => $n['variant'],
                    'quantity' => $n['quantity'],
                    'buy_price' => $n['buy_price'],
                    'new_sell_price' => $n['new_sell_price'] ?? ($existing['price'] ?? null),
                    'exists' => (bool) $existing,
                    'current_sell_price' => $existing['price'] !== null ? (float) ($existing['price'] ?? null) : null,
                    'row_error' => $rowError,
                ];
            }
            fclose($handle);

            if (!$preview) {
                json_response(['success' => false, 'message' => 'No rows found. Check your CSV has a header row and at least one data row.'], 400);
            }

            json_response([
                'success' => true,
                'preview' => true,
                'purchase_date' => !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : date('Y-m-d'),
                'items' => $preview,
            ]);
        }

        // ---- (b) Apply: manual entry, or a confirmed/edited CSV review ----
        $body = read_json_body();
        $purchaseDate = !empty($body['purchase_date']) ? (string) $body['purchase_date'] : today_str();
        assert_date_allowed($admin, $purchaseDate);
        $notes = trim($body['notes'] ?? '');
        $paidFrom = in_array($body['paid_from'] ?? 'other', ['cash', 'online', 'other'], true) ? $body['paid_from'] : 'other';
        $items = $body['items'] ?? [];
        ensure_product_unit_column($pdo);   // DDL must run before the transaction opens
        $source = in_array($body['source'] ?? '', ['manual', 'excel'], true) ? $body['source'] : 'manual';

        if (!is_array($items) || count($items) === 0) {
            json_response(['success' => false, 'message' => 'No items to add.'], 400);
        }

        $pdo->beginTransaction();
        try {
            $totalCost = 0;
            $appliedItems = [];
            foreach ($items as $item) {
                [$applied, $err] = apply_purchase_item($pdo, $item);
                if ($err) {
                    throw new Exception($err);
                }
                $totalCost += $applied['pack_price'] * $applied['packs_received'];
                $appliedItems[] = $applied;
            }

            $insertPurchase = $pdo->prepare('INSERT INTO purchases (source, total_cost, notes, recorded_by, purchase_date, paid_from) VALUES (?, ?, ?, ?, ?, ?)');
            $insertPurchase->execute([$source, $totalCost, $notes ?: null, $admin['id'], $purchaseDate, $paidFrom]);
            $purchaseId = $pdo->lastInsertId();

            $insertItem = $pdo->prepare('INSERT INTO purchase_items
                (purchase_id, product_id, product_name, pack_size, pack_price, packs_received, unit_buy_price, quantity_units, new_sell_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($appliedItems as $ai) {
                $insertItem->execute([
                    $purchaseId, $ai['product_id'], $ai['product_name'], $ai['pack_size'], $ai['pack_price'],
                    $ai['packs_received'], $ai['unit_buy_price'], $ai['quantity_units'], $ai['new_sell_price'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not add stock: ' . $e->getMessage()], 400);
        }

        log_activity($pdo, $admin, 'create', 'purchase', $purchaseId, count($appliedItems) . ' item(s) added to stock, total ' . number_format((float) $totalCost) . " (paid from {$paidFrom}, source {$source})");
        json_response([
            'success' => true,
            'message' => count($appliedItems) . ' item(s) added to stock.',
            'id' => $purchaseId,
            'items_count' => count($appliedItems),
        ], 201);
        break;

    default:
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
