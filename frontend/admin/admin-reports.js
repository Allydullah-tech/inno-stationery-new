/* Reports page logic */
(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    if (blockWorkers(admin)) return;
    renderAdminShell('reports', 'Reports', admin);

    document.querySelectorAll('input[name="period"]').forEach(r => {
        r.addEventListener('change', () => {
            document.getElementById('customDateRow').style.display = r.value === 'custom' ? 'grid' : 'none';
        });
    });

    document.getElementById('generateBtn').addEventListener('click', generateReport);
    document.getElementById('downloadCsvBtn').addEventListener('click', () => downloadReport('csv'));
    document.getElementById('downloadPdfBtn').addEventListener('click', () => downloadReport('pdf'));

    generateReport();
})();

function currentQuery() {
    const period = document.querySelector('input[name="period"]:checked').value;
    let query = `?period=${period}`;
    if (period === 'custom') {
        const from = document.getElementById('customFrom').value;
        const to = document.getElementById('customTo').value;
        query += `&from=${from}&to=${to}`;
    }
    return query;
}

async function generateReport() {
    const period = document.querySelector('input[name="period"]:checked').value;
    if (period === 'custom' && (!document.getElementById('customFrom').value || !document.getElementById('customTo').value)) {
        adminToast('Please choose both a from and to date.', 'error');
        return;
    }

    const resultBox = document.getElementById('reportResult');
    resultBox.innerHTML = `<div class="skeleton" style="height:200px;"></div>`;

    const res = await adminApi('/reports.php' + currentQuery());
    if (!res.success) {
        resultBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message || 'Could not generate report.')}</div>`;
        return;
    }

    const s = res.summary;
    const unknownCostNote = s.unknown_cost_sale_count > 0
        ? `<div class="alert alert-info" style="margin-bottom:16px;">${s.unknown_cost_sale_count} sale(s) totaling ${adminFormatMoney(s.unknown_cost_revenue)} have no known cost (a service sold with no buying cost set) and are <strong>excluded</strong> from the profit figures below — they are not assumed to have cost TSh 0.</div>`
        : '';

    resultBox.innerHTML = `
      <div class="stat-grid">
        <div class="stat-card accent"><div class="stat-label">Total Sales</div><div class="stat-value">${adminFormatMoney(s.total_sales)}</div></div>
        <div class="stat-card"><div class="stat-label">Items Sold</div><div class="stat-value">${s.total_items_sold}</div></div>
        <div class="stat-card warn"><div class="stat-label">Total Expenses</div><div class="stat-value">${adminFormatMoney(s.total_expenses)}</div></div>
        <div class="stat-card danger"><div class="stat-label">Damage Loss</div><div class="stat-value">${adminFormatMoney(s.total_damage_loss)}</div></div>
        <div class="stat-card warn"><div class="stat-label">Service Materials Bought</div><div class="stat-value">${adminFormatMoney(s.service_materials_cost || 0)}</div></div>
        <div class="stat-card"><div class="stat-label">Credit Sales</div><div class="stat-value">${adminFormatMoney(s.credit_sales || 0)}</div></div>
        <div class="stat-card"><div class="stat-label">Debts Collected</div><div class="stat-value">${adminFormatMoney(s.debts_collected || 0)}</div></div>
        <div class="stat-card"><div class="stat-label">Net Profit</div><div class="stat-value" style="color:var(--emerald);">${adminFormatMoney(s.net_profit)}</div></div>
      </div>
      <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">Showing ${s.from} to ${s.to}. Net profit = profit on items sold − expenses − damage loss − service materials bought.</p>
      <div class="v3-grid-2" style="margin-bottom:18px;">
        <div class="panel"><div class="panel-head"><h3>Top selling items</h3></div><div class="panel-body table-wrap"><table class="data-table"><thead><tr><th>Item</th><th>Qty</th><th>Revenue</th></tr></thead><tbody>
          ${(res.top_selling || []).length ? res.top_selling.map(t => `<tr><td>${adminEscapeHtml(t.product_name)}</td><td>${t.qty}</td><td class="mono">${adminFormatMoney(t.revenue)}</td></tr>`).join('') : '<tr><td colspan="3" class="v3-muted">No sales in this period.</td></tr>'}
        </tbody></table></div></div>
        <div class="panel"><div class="panel-head"><h3>Where the expenses went</h3></div><div class="panel-body table-wrap"><table class="data-table"><thead><tr><th>Category</th><th>Amount</th></tr></thead><tbody>
          ${(res.expense_breakdown || []).length ? res.expense_breakdown.map(t => `<tr><td>${adminEscapeHtml(t.category)}</td><td class="mono">${adminFormatMoney(t.amount)}</td></tr>`).join('') : '<tr><td colspan="2" class="v3-muted">No expenses in this period.</td></tr>'}
        </tbody></table></div></div>
      </div>
      ${unknownCostNote}

      <div class="panel">
        <div class="panel-head"><h3>Sales (${res.sales.length})</h3></div>
        <div class="panel-body table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Item</th><th>Type</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
            <tbody>${res.sales.length ? res.sales.map(x => {
                const hasDiscount = x.list_price !== null && Number(x.list_price) > Number(x.unit_price);
                const priceCell = hasDiscount
                    ? `<span class="list-price-strike">${adminFormatMoney(x.list_price)}</span>${adminFormatMoney(x.unit_price)}<span class="discount-tag">Discount</span>`
                    : adminFormatMoney(x.unit_price);
                const typeBadge = x.item_type === 'service' ? `<span class="status-pill status-service">Service</span>` : `<span class="status-pill status-product">Product</span>`;
                return `<tr><td>${x.sale_date}</td><td>${adminEscapeHtml(x.product_name)}</td><td>${typeBadge}</td><td>${x.quantity}</td><td class="mono">${priceCell}</td><td class="mono">${adminFormatMoney(x.total_amount)}</td></tr>`;
            }).join('') : '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--ink-soft);">No sales in this period.</td></tr>'}</tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Expenses (${res.expenses.length})</h3></div>
        <div class="panel-body table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th></tr></thead>
            <tbody>${res.expenses.length ? res.expenses.map(x => `<tr><td>${x.expense_date}</td><td>${adminEscapeHtml(x.description)}</td><td>${adminEscapeHtml(x.category || '—')}</td><td class="mono">${adminFormatMoney(x.amount)}</td></tr>`).join('') : '<tr><td colspan="4" style="text-align:center;padding:20px;color:var(--ink-soft);">No expenses in this period.</td></tr>'}</tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Damages (${res.damages.length})</h3></div>
        <div class="panel-body table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Item</th><th>Source</th><th>Category</th><th>Qty</th><th>Reason</th><th>Loss</th></tr></thead>
            <tbody>${res.damages.length ? res.damages.map(x => `<tr><td>${x.damage_date}</td><td>${adminEscapeHtml(x.product_name)}</td><td>${x.is_stock_item ? '<span class="status-pill status-product">Stock</span>' : '<span class="status-pill status-inactive">Non-Stock</span>'}</td><td>${adminEscapeHtml(x.category || '—')}</td><td>${x.quantity}</td><td style="text-transform:capitalize;">${x.reason}</td><td class="mono">${adminFormatMoney(x.loss_amount)}</td></tr>`).join('') : '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--ink-soft);">No damages in this period.</td></tr>'}</tbody>
          </table>
        </div>
      </div>
    `;
}

function downloadReport(format) {
    const period = document.querySelector('input[name="period"]:checked').value;
    if (period === 'custom' && (!document.getElementById('customFrom').value || !document.getElementById('customTo').value)) {
        adminToast('Please choose both a from and to date.', 'error');
        return;
    }
    window.open(`${ADMIN_API_BASE}/reports.php${currentQuery()}&download=${format}`, '_blank');
}
