<?php
/**
 * Service materials (paper, toner, ink, lamination film ...).
 * A service sale line can list the materials it used; stock is deducted
 * when the sale is saved and given back when the sale is edited/deleted.
 */

function sm_display_name(array $item): string
{
    return trim($item['description'] ?? '') === '' ? $item['name'] : $item['name'] . ' — ' . $item['description'];
}

/**
 * Deduct materials for one sale row. Must be called inside a transaction.
 * $materials = [['service_item_id'=>1,'quantity'=>3], ...]
 * Throws RuntimeException with a user-friendly message when stock is short.
 */
function sm_deduct(PDO $pdo, int $saleId, array $materials): void
{
    $need = [];
    foreach ($materials as $m) {
        $id = (int) ($m['service_item_id'] ?? 0);
        $q = (int) ($m['quantity'] ?? 0);
        if ($id <= 0 || $q <= 0) {
            continue;
        }
        $need[$id] = ($need[$id] ?? 0) + $q;
    }
    foreach ($need as $id => $q) {
        $st = $pdo->prepare('SELECT * FROM service_items WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $item = $st->fetch();
        if (!$item) {
            throw new RuntimeException('A material you selected no longer exists.');
        }
        if ((int) $item['stock_quantity'] < $q) {
            throw new RuntimeException('Not enough ' . sm_display_name($item) . ' in service stock (' . (int) $item['stock_quantity'] . ' left, ' . $q . ' needed).');
        }
        $pdo->prepare('UPDATE service_items SET stock_quantity = stock_quantity - ? WHERE id = ?')->execute([$q, $id]);
        $pdo->prepare('INSERT INTO sale_materials (sale_id, service_item_id, quantity, unit_cost) VALUES (?,?,?,?)')
            ->execute([$saleId, $id, $q, $item['buying_price']]);
    }
}

/** Put a sale's materials back into stock and forget them. Inside a transaction. */
function sm_restore(PDO $pdo, int $saleId): void
{
    $st = $pdo->prepare('SELECT service_item_id, quantity FROM sale_materials WHERE sale_id = ?');
    $st->execute([$saleId]);
    foreach ($st->fetchAll() as $m) {
        $pdo->prepare('UPDATE service_items SET stock_quantity = stock_quantity + ? WHERE id = ?')->execute([$m['quantity'], $m['service_item_id']]);
    }
    $pdo->prepare('DELETE FROM sale_materials WHERE sale_id = ?')->execute([$saleId]);
}
