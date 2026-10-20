<?php
/**
 * Cash-audit calculations shared by cash_audit.php, cash_float.php and
 * bank_deposits.php.
 *
 * Expected CASH in the drawer
 *   = opening cash
 *   + cash sales (paid cash in hand)
 *   + debt payments received in cash
 *   + change customers left with the shop (held)
 *   - change handed back to customers
 *   - cash expenses and cash supplier payments
 * Expected ONLINE money (mobile money)
 *   = online sales + online debt payments - online expenses / supplier payments
 */

const CASH_DENOMS = [10000, 5000, 2000, 1000, 500, 200, 100, 50];

function ch_sum(PDO $pdo, string $sql, array $params): float
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return round((float) $st->fetchColumn(), 2);
}

/** Everything the system knows about one day's money. */
function ch_day_figures(PDO $pdo, string $date): array
{
    $cashSales = ch_sum($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=? AND sale_type='cash' AND payment_method='cash_in_hand'", [$date]);
    $onlineSales = ch_sum($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=? AND sale_type='cash' AND (payment_method IS NULL OR payment_method<>'cash_in_hand')", [$date]);
    $creditSales = ch_sum($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=? AND sale_type='credit'", [$date]);
    $debtCash = ch_sum($pdo, "SELECT COALESCE(SUM(amount),0) FROM debt_payments WHERE payment_date=? AND payment_method='cash'", [$date]);
    $debtOnline = ch_sum($pdo, "SELECT COALESCE(SUM(amount),0) FROM debt_payments WHERE payment_date=? AND payment_method<>'cash'", [$date]);

    $expCash = ch_sum($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date=? AND paid_from='cash'", [$date]);
    $expOnline = ch_sum($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date=? AND paid_from='online'", [$date]);
    $supCash = ch_sum($pdo, "SELECT COALESCE(SUM(total_cost),0) FROM service_purchases WHERE purchase_date=? AND paid_from='cash'", [$date])
        + ch_sum($pdo, "SELECT COALESCE(SUM(total_cost),0) FROM purchases WHERE purchase_date=? AND paid_from='cash'", [$date]);
    $supOnline = ch_sum($pdo, "SELECT COALESCE(SUM(total_cost),0) FROM service_purchases WHERE purchase_date=? AND paid_from='online'", [$date])
        + ch_sum($pdo, "SELECT COALESCE(SUM(total_cost),0) FROM purchases WHERE purchase_date=? AND paid_from='online'", [$date]);

    $changeHeld = ch_sum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM change_holds WHERE hold_date=?', [$date]);
    $changePaid = ch_sum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM change_collections WHERE collected_date=?', [$date]);

    return [
        'cash_sales' => $cashSales,
        'online_sales' => $onlineSales,
        'credit_sales' => $creditSales,
        'total_sales' => round($cashSales + $onlineSales + $creditSales, 2),
        'debt_cash' => $debtCash,
        'debt_online' => $debtOnline,
        'expenses_cash' => round($expCash + $supCash, 2),
        'expenses_online' => round($expOnline + $supOnline, 2),
        'expense_part_cash' => $expCash,
        'supplier_part_cash' => $supCash,
        'change_held' => $changeHeld,
        'change_paid' => $changePaid,
    ];
}

/** Cash left in the office by the most recent audit before $date (after banking). */
function ch_carry_over(PDO $pdo, string $date): array
{
    $st = $pdo->prepare('SELECT audit_date, remaining_cash FROM cash_audits WHERE audit_date < ? ORDER BY audit_date DESC, id DESC LIMIT 1');
    $st->execute([$date]);
    $row = $st->fetch();
    return $row ? ['amount' => round((float) $row['remaining_cash'], 2), 'from' => $row['audit_date']] : ['amount' => 0.0, 'from' => null];
}

/** Opening cash for a day = (typed opening OR carry-over) + top-ups - cash taken out. */
function ch_float_summary(PDO $pdo, string $date): array
{
    $carry = ch_carry_over($pdo, $date);
    $op = $pdo->prepare("SELECT amount FROM cash_floats WHERE float_date=? AND entry_type='opening' ORDER BY id DESC LIMIT 1");
    $op->execute([$date]);
    $typed = $op->fetchColumn();
    $base = $typed !== false ? round((float) $typed, 2) : $carry['amount'];
    $adds = ch_sum($pdo, "SELECT COALESCE(SUM(amount),0) FROM cash_floats WHERE float_date=? AND entry_type='add'", [$date]);
    $reds = ch_sum($pdo, "SELECT COALESCE(SUM(amount),0) FROM cash_floats WHERE float_date=? AND entry_type='reduce'", [$date]);
    return [
        'base' => $base,
        'base_source' => $typed !== false ? 'typed' : ($carry['from'] ? 'carry_over' : 'none'),
        'carry_over' => $carry['amount'],
        'carry_over_from' => $carry['from'],
        'adds' => $adds,
        'reduces' => $reds,
        'total' => round($base + $adds - $reds, 2),
    ];
}

/** Full live calculation for a date (what the Cash Audit page shows). */
function ch_calculate(PDO $pdo, string $date): array
{
    $f = ch_day_figures($pdo, $date);
    $float = ch_float_summary($pdo, $date);
    $f['opening_cash'] = $float['total'];
    $f['expected_cash'] = round($float['total'] + $f['cash_sales'] + $f['debt_cash'] + $f['change_held'] - $f['change_paid'] - $f['expenses_cash'], 2);
    $f['expected_online'] = round($f['online_sales'] + $f['debt_online'] - $f['expenses_online'], 2);
    $f['float'] = $float;
    return $f;
}

/** Sum a denominations payload [{denom, qty}] after validating each denomination. Returns [total, cleanRows] or [null, error]. */
function ch_count_denominations($rows): array
{
    if (!is_array($rows)) {
        return [null, 'Please enter the cash count.'];
    }
    $total = 0;
    $clean = [];
    foreach ($rows as $r) {
        $denom = (int) ($r['denom'] ?? 0);
        $qty = (int) ($r['qty'] ?? 0);
        if ($qty === 0) {
            continue;
        }
        if (!in_array($denom, CASH_DENOMS, true)) {
            return [null, 'Unknown note/coin value: ' . $denom];
        }
        if ($qty < 0 || $qty > 100000) {
            return [null, 'Invalid quantity for ' . $denom . '.'];
        }
        $clean[] = ['denom' => $denom, 'qty' => $qty];
        $total += $denom * $qty;
    }
    return [round((float) $total, 2), $clean];
}

/** Re-sum a day's bank deposits onto that day's audit row. */
function ch_sync_deposits(PDO $pdo, string $date): void
{
    $dep = ch_sum($pdo, 'SELECT COALESCE(SUM(amount),0) FROM bank_deposits WHERE deposit_date=?', [$date]);
    $pdo->prepare('UPDATE cash_audits SET bank_deposit=?, remaining_cash=GREATEST(counted_cash-?,0) WHERE audit_date=?')
        ->execute([$dep, $dep, $date]);
}
