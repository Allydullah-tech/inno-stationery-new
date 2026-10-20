/* Purchases page logic */
let productList = [];
let manualRowCount = 0;
let lastPurchases = [];

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    if (blockWorkers(admin)) return;
    renderAdminShell('purchases', 'Purchases', admin);

    document.getElementById('manDate').value = adminToday();

    await refreshProductList();

    loadPurchases();

    document.getElementById('filterPeriod').addEventListener('change', () => {
        document.getElementById('customRangeWrap').style.display = document.getElementById('filterPeriod').value === 'custom' ? 'inline-flex' : 'none';
    });
    document.getElementById('purchaseFilterBtn').addEventListener('click', loadPurchases);
    document.getElementById('filterSource').addEventListener('change', loadPurchases);

    // ---- Manual mode ----
    document.getElementById('addItemRowBtn').addEventListener('click', () => addManualItemRow());
    document.getElementById('manualForm').addEventListener('submit', submitManual);
    addManualItemRow();

    // ---- Purchase breakdown modal ----
    document.getElementById('breakdownModalClose').addEventListener('click', closePurchaseBreakdown);
    document.getElementById('purchaseBreakdownBackdrop').addEventListener('click', (e) => {
        if (e.target === document.getElementById('purchaseBreakdownBackdrop')) closePurchaseBreakdown();
    });
})();

/** Products with their types, shaped for the shared product search box. */
async function refreshProductList() {
    const res = await adminApi('/products.php?status=active&grouped=1');
    productList = res.success
        ? res.groups.map(g => ({ id: g.group_id, name: g.name, stock: g.total_stock, types: g.types }))
        : [];
}

// ---------------------------------------------------------
// Manual entry
// ---------------------------------------------------------
function ensureUnitList() {
    if (document.getElementById('unit-options')) return;
    const dl = document.createElement('datalist'); dl.id = 'unit-options';
    dl.innerHTML = ['pcs', 'box', 'dozen', 'pack', 'ream', 'set', 'roll', 'bottle', 'kg', 'metre'].map(u => `<option value="${u}">`).join('');
    document.body.appendChild(dl);
}

function addManualItemRow() {
    ensureUnitList();
    const rowId = manualRowCount++;
    const wrap = document.getElementById('manualItemsWrap');
    const row = document.createElement('div');
    row.className = 'purchase-item-row';
    row.dataset.rowId = rowId;
    row.innerHTML = `
      <button type="button" class="remove-row-btn" aria-label="Remove item">&times;</button>
      <div class="field">
        <label>Product name *</label>
        <div class="product-search-wrap">
          <input type="text" class="man-product-search" placeholder="Type to search or add new…" autocomplete="off" required>
          <div class="product-search-suggestions"></div>
        </div>
        <div class="hint man-product-hint">New product — add its types below.</div>
      </div>
      <div class="man-type-head type-head"><span>Type name</span><span>Unit</span><span>Quantity *</span><span>Buy price *</span><span>Sell price</span><span title="Lowest price a cashier may sell at">Min. sell price</span><span></span></div>
      <div class="man-types"></div>
      <button type="button" class="btn btn-outline btn-sm man-add-type" style="margin-top:6px;">+ Add type</button>
    `;
    wrap.appendChild(row);

    const searchInput = row.querySelector('.man-product-search');
    row.querySelector('.remove-row-btn').addEventListener('click', () => row.remove());
    row.querySelector('.man-add-type').addEventListener('click', () => addManualTypeLine(row));
    addManualTypeLine(row);

    attachProductSearch(
        searchInput,
        row.querySelector('.product-search-suggestions'),
        () => productList,
        (product) => {
            row.dataset.groupId = product.id;
            row.querySelector('.man-product-hint').textContent = `Existing product — pick one of its types or type a new type name to add one.`;
            refreshTypeHints(row);
        }
    );

    // Typing a name that doesn't match a selected suggestion means "new product".
    searchInput.addEventListener('input', () => {
        delete row.dataset.groupId;
        row.querySelector('.man-product-hint').textContent = 'New product — add its types below.';
        refreshTypeHints(row);
    });
}

/** The existing product (with types) this row refers to, matched by selection or exact name. */
function rowProduct(row) {
    if (row.dataset.groupId) return productList.find(p => String(p.id) === row.dataset.groupId) || null;
    const name = row.querySelector('.man-product-search').value.trim().toLowerCase();
    return name ? productList.find(p => p.name.toLowerCase() === name) || null : null;
}

function addManualTypeLine(row) {
    const line = document.createElement('div');
    line.className = 'man-type-line';
    line.innerHTML = `
      <div class="type-row">
        <input type="text" class="man-variant" placeholder="e.g. Blue (blank = no type)" autocomplete="off">
        <input type="text" class="man-unit" list="unit-options" maxlength="30" placeholder="pcs" value="pcs" autocomplete="off">
        <input type="number" class="man-quantity" min="1" step="1" value="1" required>
        <input type="number" class="man-buy-price" min="0" required placeholder="Buy">
        <input type="number" class="man-sell-price" min="0" placeholder="Sell">
        <input type="number" class="man-min-price" min="0" placeholder="Min" title="Lowest price a cashier may sell at (optional)">
        <button type="button" class="icon-btn danger" aria-label="Remove type">${adminIcon('trash', 14)}</button>
      </div>
      <div class="hint man-line-hint" style="margin:0 0 8px;"></div>`;
    line.querySelector('button').addEventListener('click', () => {
        if (row.querySelectorAll('.man-type-line').length > 1) line.remove();
        else adminToast('Keep at least one line.', 'error');
    });
    line.querySelector('.man-unit').addEventListener('input', e => { e.target.dataset.touched = '1'; });
    line.querySelector('.man-variant').addEventListener('input', () => refreshTypeHints(row));
    row.querySelector('.man-types').appendChild(line);
    refreshTypeHints(row);
}

/** Per-line hint: existing type (shows current price) vs new type (sell price required). */
function refreshTypeHints(row) {
    const product = rowProduct(row);
    row.querySelectorAll('.man-type-line').forEach(line => {
        const variant = line.querySelector('.man-variant').value.trim();
        const existing = product ? product.types.find(t => (t.variant || '').toLowerCase() === variant.toLowerCase()) : null;
        const sell = line.querySelector('.man-sell-price');
        const hint = line.querySelector('.man-line-hint');
        if (existing) {
            sell.placeholder = 'Keep current';
            const mp = line.querySelector('.man-min-price'); mp.placeholder = existing.minimum_price != null ? 'Keep ' + existing.minimum_price : 'Min';
            const un = line.querySelector('.man-unit'); if (existing.unit && !un.dataset.touched) un.value = existing.unit;
            hint.textContent = `Existing type — ${existing.stock} in stock, selling at ${adminFormatMoney(existing.price)}. Leave sell price blank to keep it.`;
        } else {
            sell.placeholder = 'Sell *';
            hint.textContent = product
                ? `New type for "${product.name}" — a selling price is required.`
                : 'New product — a selling price is required.';
        }
    });
    // Offer the product's existing types as suggestions.
    row.querySelectorAll('.man-variant').forEach(inp => {
        let dl = row.querySelector('datalist');
        if (!dl) {
            dl = document.createElement('datalist');
            dl.id = 'dl-' + row.dataset.rowId;
            row.appendChild(dl);
        }
        inp.setAttribute('list', dl.id);
        dl.innerHTML = product ? product.types.filter(t => t.variant).map(t => `<option value="${adminEscapeHtml(t.variant)}">`).join('') : '';
    });
}

async function submitManual(e) {
    e.preventDefault();
    const alertBox = document.getElementById('manualAlert');
    alertBox.innerHTML = '';

    const rows = document.querySelectorAll('#manualItemsWrap .purchase-item-row');
    if (!rows.length) {
        alertBox.innerHTML = `<div class="alert alert-error">Please add at least one item.</div>`;
        return;
    }

    const items = [];
    for (const row of rows) {
        const productName = row.querySelector('.man-product-search').value.trim();
        const product = rowProduct(row);
        const baseName = product ? product.name : productName;
        if (!productName) {
            alertBox.innerHTML = `<div class="alert alert-error">Please enter a product name for every item.</div>`;
            return;
        }

        for (const line of row.querySelectorAll('.man-type-line')) {
            const variant = line.querySelector('.man-variant').value.trim();
            const quantity = line.querySelector('.man-quantity').value;
            const buyPrice = line.querySelector('.man-buy-price').value;
            const newSellPrice = line.querySelector('.man-sell-price').value;
            const minPrice = line.querySelector('.man-min-price').value;
            const unit = line.querySelector('.man-unit').value.trim() || 'pcs';
            const label = variant ? `${baseName} - ${variant}` : baseName;
            const existing = product ? product.types.find(t => (t.variant || '').toLowerCase() === variant.toLowerCase()) : null;

            if (!buyPrice || !quantity) {
                alertBox.innerHTML = `<div class="alert alert-error">Please fill in quantity and buy price for "${adminEscapeHtml(label)}".</div>`;
                return;
            }
            if (!existing && !newSellPrice) {
                alertBox.innerHTML = `<div class="alert alert-error">"${adminEscapeHtml(label)}" is new — please provide a selling price for it.</div>`;
                line.querySelector('.man-sell-price').focus();
                return;
            }

            const effSell = newSellPrice ? Number(newSellPrice) : (existing ? Number(existing.price) : null);
            if (minPrice !== '' && effSell !== null && Number(minPrice) > effSell) {
                alertBox.innerHTML = `<div class="alert alert-error">The minimum selling price of "${adminEscapeHtml(label)}" cannot be higher than its selling price.</div>`;
                line.querySelector('.man-min-price').focus();
                return;
            }

            items.push({
                product_id: existing ? existing.id : null,
                product_name: baseName,
                variant,
                quantity: Number(quantity),
                buy_price: Number(buyPrice),
                new_sell_price: newSellPrice ? Number(newSellPrice) : null,
                minimum_price: minPrice !== '' ? Number(minPrice) : null,
                unit,
            });
        }
    }

    // The same product + type twice in one submission would be ambiguous.
    const keys = items.map(i => (i.product_name + '|' + i.variant).toLowerCase());
    if (new Set(keys).size !== keys.length) {
        alertBox.innerHTML = `<div class="alert alert-error">The same product type appears more than once — combine those lines.</div>`;
        return;
    }

    const btn = document.getElementById('manualSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    const res = await adminApi('/purchases.php', {
        method: 'POST',
        body: {
            purchase_date: document.getElementById('manDate').value,
            items,
        },
    });

    btn.disabled = false;
    btn.textContent = 'Add to Stock';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    alertBox.innerHTML = `<div class="alert alert-success">${adminEscapeHtml(res.message)}</div>`;
    adminToast('Stock updated.', 'success');
    document.getElementById('manualForm').reset();
    document.getElementById('manDate').value = adminToday();
    document.getElementById('manualItemsWrap').innerHTML = '';
    manualRowCount = 0;
    addManualItemRow();

    await refreshProductList();

    loadPurchases();
}

async function loadPurchases() {
    const tbody = document.getElementById('purchasesTbody');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const source = document.getElementById('filterSource').value;
    const period = document.getElementById('filterPeriod').value;
    let query = '?limit=100&';
    if (source) query += `source=${source}&`;
    if (period && period !== 'all') {
        query += `period=${period}&`;
        if (period === 'custom') {
            const from = document.getElementById('filterFrom').value;
            const to = document.getElementById('filterTo').value;
            if (!from || !to) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:34px;color:var(--ink-soft);">Please choose both a from and to date for a custom range.</td></tr>`;
                renderPurchaseSummary({ total_cost: 0, total_products: 0, total_items: 0 });
                return;
            }
            query += `from=${from}&to=${to}&`;
        }
    }

    const res = await adminApi('/purchases.php' + query);
    const purchases = res.success ? res.purchases : [];
    lastPurchases = purchases;

    renderPurchaseSummary(res.summary || { total_cost: 0, total_products: 0, total_items: 0 });

    if (!purchases.length) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:34px;color:var(--ink-soft);">No purchase records found for the selected period.</td></tr>`;
        return;
    }

    const sourceLabel = { manual: 'Manual Entry', excel: 'Excel Import' };
    const sourceClass = { manual: 'status-manual', excel: 'status-excel' };

    tbody.innerHTML = purchases.map((p, i) => {
        const namesPreview = p.items.slice(0, 3).map(i => `<div>${adminEscapeHtml(i.product_name)} (+${i.quantity_units})</div>`).join('');
        const more = p.items.length > 3 ? `<div class="v3-muted">+${p.items.length - 3} more</div>` : '';
        return `
      <tr>
        <td>${p.purchase_date}</td>
        <td>${namesPreview}${more}</td>
        <td><span class="status-pill ${sourceClass[p.source] || 'status-manual'}">${sourceLabel[p.source] || 'Manual Entry'}</span></td>
        <td class="mono">${adminFormatMoney(p.total_cost)}</td>
        <td>${adminEscapeHtml(p.recorded_by_name || '—')}</td>
        <td><button class="btn btn-outline btn-sm" onclick="openPurchaseBreakdown(${i})">View</button></td>
      </tr>
    `;
    }).join('');
}

function openPurchaseBreakdown(index) {
    const p = lastPurchases[index];
    if (!p) return;

    const sourceLabel = { manual: 'Manual Entry', excel: 'Excel Import' };
    document.getElementById('breakdownTitle').textContent = `Purchase breakdown — ${p.purchase_date}`;
    document.getElementById('breakdownMetaGrid').innerHTML = `
      <div class="ddg-item"><div class="ddg-label">Date</div><div class="ddg-value">${p.purchase_date}</div></div>
      <div class="ddg-item"><div class="ddg-label">Source</div><div class="ddg-value">${sourceLabel[p.source] || 'Manual Entry'}</div></div>
      <div class="ddg-item"><div class="ddg-label">Items</div><div class="ddg-value">${p.items.length}</div></div>
      <div class="ddg-item"><div class="ddg-label">Recorded By</div><div class="ddg-value">${adminEscapeHtml(p.recorded_by_name || '—')}</div></div>
      ${p.notes ? `<div class="ddg-item" style="grid-column:1/-1;"><div class="ddg-label">Notes</div><div class="ddg-value" style="font-weight:600;">${adminEscapeHtml(p.notes)}</div></div>` : ''}
    `;

    document.getElementById('breakdownItemsTbody').innerHTML = p.items.map(item => {
        const lineTotal = item.buy_price * item.quantity;
        const statusBadge = item.product_id
            ? '<span class="status-pill status-active">In Stock</span>'
            : '<span class="status-pill status-inactive">Removed</span>';
        return `
      <tr>
        <td><strong>${adminEscapeHtml(item.product_name)}</strong></td>
        <td>${statusBadge}</td>
        <td>${item.quantity}</td>
        <td class="mono">${adminFormatMoney(item.buy_price)}</td>
        <td class="mono">${adminFormatMoney(lineTotal)}</td>
      </tr>
    `;
    }).join('');

    document.getElementById('breakdownTotal').textContent = adminFormatMoney(p.total_cost);
    document.getElementById('purchaseBreakdownBackdrop').classList.add('open');
}

function closePurchaseBreakdown() {
    document.getElementById('purchaseBreakdownBackdrop').classList.remove('open');
}

function renderPurchaseSummary(summary) {
    document.getElementById('purchaseSummaryGrid').innerHTML = `
      <div class="summary-card">
        <div class="dsc-label">Total Purchase Cost</div>
        <div class="dsc-value">${adminFormatMoney(summary.total_cost)}</div>
      </div>
      <div class="summary-card">
        <div class="dsc-label">Total Products Purchased</div>
        <div class="dsc-value">${summary.total_products}</div>
      </div>
      <div class="summary-card">
        <div class="dsc-label">Total Items / Quantity</div>
        <div class="dsc-value">${summary.total_items}</div>
      </div>
    `;
}
