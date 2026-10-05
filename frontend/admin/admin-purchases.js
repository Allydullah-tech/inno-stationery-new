/* Purchases page logic */
let selectedFile = null;
let productList = [];
let manualRowCount = 0;
let lastPurchases = [];

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    if (blockWorkers(admin)) return;
    renderAdminShell('purchases', 'Purchases', admin);

    document.getElementById('impDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('manDate').value = new Date().toISOString().slice(0, 10);

    const productsRes = await adminApi('/products.php?status=active');
    productList = productsRes.success ? productsRes.products : [];

    loadPurchases();

    document.getElementById('filterPeriod').addEventListener('change', () => {
        document.getElementById('customRangeWrap').style.display = document.getElementById('filterPeriod').value === 'custom' ? 'inline-flex' : 'none';
    });
    document.getElementById('purchaseFilterBtn').addEventListener('click', loadPurchases);
    document.getElementById('filterSource').addEventListener('change', loadPurchases);

    // ---- Mode toggle ----
    document.querySelectorAll('input[name="purchaseMode"]').forEach(r => {
        r.addEventListener('change', () => {
            const isCsv = document.querySelector('input[name="purchaseMode"]:checked').value === 'csv';
            document.getElementById('csvModeWrap').style.display = isCsv ? 'block' : 'none';
            document.getElementById('manualModeWrap').style.display = isCsv ? 'none' : 'block';
            if (!isCsv && manualRowCount === 0) addManualItemRow();
        });
    });

    // ---- CSV mode ----
    const fileDrop = document.getElementById('fileDrop');
    const fileInput = document.getElementById('fileInput');
    fileDrop.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => { if (fileInput.files.length) setSelectedFile(fileInput.files[0]); });
    ['dragover', 'dragenter'].forEach(evt => fileDrop.addEventListener(evt, (e) => { e.preventDefault(); fileDrop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(evt => fileDrop.addEventListener(evt, (e) => { e.preventDefault(); fileDrop.classList.remove('drag'); }));
    fileDrop.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) setSelectedFile(e.dataTransfer.files[0]); });
    document.getElementById('importForm').addEventListener('submit', submitImportPreview);
    document.getElementById('csvStartOverBtn').addEventListener('click', resetCsvImport);
    document.getElementById('csvConfirmBtn').addEventListener('click', confirmCsvImport);

    // ---- Manual mode ----
    document.getElementById('addItemRowBtn').addEventListener('click', () => addManualItemRow());
    document.getElementById('manualForm').addEventListener('submit', submitManual);

    // ---- Purchase breakdown modal ----
    document.getElementById('breakdownModalClose').addEventListener('click', closePurchaseBreakdown);
    document.getElementById('purchaseBreakdownBackdrop').addEventListener('click', (e) => {
        if (e.target === document.getElementById('purchaseBreakdownBackdrop')) closePurchaseBreakdown();
    });
})();

function setSelectedFile(file) {
    selectedFile = file;
    document.getElementById('fileDropLabel').innerHTML = `<strong>${adminEscapeHtml(file.name)}</strong><br><small style="color:var(--ink-soft);">Click to change</small>`;
}

async function submitImportPreview(e) {
    e.preventDefault();
    const alertBox = document.getElementById('importAlert');
    alertBox.innerHTML = '';

    if (!selectedFile) {
        alertBox.innerHTML = `<div class="alert alert-error">Please choose a CSV file.</div>`;
        return;
    }

    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Reading file…';

    const fd = new FormData();
    fd.append('file', selectedFile);
    fd.append('purchase_date', document.getElementById('impDate').value);
    fd.append('mode', 'preview');

    const res = await adminApi('/purchases.php', { method: 'POST', body: fd, isForm: true });

    btn.disabled = false;
    btn.textContent = 'Review Import';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    renderCsvReview(res.items, res.purchase_date);
    document.getElementById('csvUploadStep').style.display = 'none';
    document.getElementById('csvReviewStep').style.display = '';
}

function renderCsvReview(items, purchaseDate) {
    document.getElementById('csvReviewTbody').dataset.purchaseDate = purchaseDate;
    document.getElementById('csvReviewAlert').innerHTML = '';

    document.getElementById('csvReviewTbody').innerHTML = items.map((item, i) => `
      <tr data-index="${i}" data-product-id="${item.product_id || ''}" data-exists="${item.exists ? '1' : '0'}">
        <td><strong>${adminEscapeHtml(item.product_name || '(missing name)')}</strong></td>
        <td>${item.exists ? '<span class="status-pill status-active">Existing</span>' : '<span class="status-pill status-partial">New</span>'}</td>
        <td><input type="number" class="csv-qty" min="1" step="1" value="${item.quantity || ''}" style="width:80px;"></td>
        <td><input type="number" class="csv-buy-price" min="0" value="${item.buy_price || ''}" style="width:100px;"></td>
        <td>
          <input type="number" class="csv-sell-price" min="0" value="${item.new_sell_price !== null && item.new_sell_price !== undefined ? item.new_sell_price : ''}" style="width:100px;" placeholder="${item.exists ? '' : 'Required — new product'}">
          ${item.exists ? `<div class="hint" style="margin:2px 0 0;">Current: ${adminFormatMoney(item.current_sell_price || 0)}</div>` : ''}
        </td>
        <td><button type="button" class="icon-btn danger" onclick="this.closest('tr').remove()" aria-label="Remove row">${adminIcon('trash', 14)}</button></td>
      </tr>
      ${item.row_error ? `<tr class="csv-row-error-tr"><td colspan="6" style="color:var(--red);font-size:12px;padding:2px 10px 8px;">⚠ ${adminEscapeHtml(item.row_error)}</td></tr>` : ''}
    `).join('');
}

function resetCsvImport() {
    document.getElementById('csvReviewStep').style.display = 'none';
    document.getElementById('csvUploadStep').style.display = '';
    document.getElementById('csvReviewTbody').innerHTML = '';
    document.getElementById('importForm').reset();
    document.getElementById('impDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('fileDropLabel').textContent = 'Click to choose a .csv file, or drag it here';
    selectedFile = null;
}

async function confirmCsvImport() {
    const alertBox = document.getElementById('csvReviewAlert');
    alertBox.innerHTML = '';

    const rows = document.querySelectorAll('#csvReviewTbody tr[data-index]');
    if (!rows.length) {
        alertBox.innerHTML = `<div class="alert alert-error">No rows left to import — add at least one, or start over.</div>`;
        return;
    }

    const items = [];
    for (const row of rows) {
        const productName = row.querySelector('td strong').textContent.trim();
        const quantity = row.querySelector('.csv-qty').value;
        const buyPrice = row.querySelector('.csv-buy-price').value;
        const sellPrice = row.querySelector('.csv-sell-price').value;
        const exists = row.dataset.exists === '1';

        if (!productName || productName === '(missing name)' || !quantity || Number(quantity) <= 0 || !buyPrice || Number(buyPrice) <= 0) {
            alertBox.innerHTML = `<div class="alert alert-error">"${adminEscapeHtml(productName)}" needs a valid quantity and buy price.</div>`;
            row.style.outline = '2px solid var(--red)';
            return;
        }
        if (!exists && !sellPrice) {
            alertBox.innerHTML = `<div class="alert alert-error">"${adminEscapeHtml(productName)}" is a new product — please fill in a selling price for it before confirming.</div>`;
            row.style.outline = '2px solid var(--red)';
            row.querySelector('.csv-sell-price').focus();
            return;
        }

        items.push({
            product_id: row.dataset.productId ? Number(row.dataset.productId) : null,
            product_name: productName,
            quantity: Number(quantity),
            buy_price: Number(buyPrice),
            new_sell_price: sellPrice ? Number(sellPrice) : null,
        });
    }

    const btn = document.getElementById('csvConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Adding to stock…';

    const res = await adminApi('/purchases.php', {
        method: 'POST',
        body: {
            purchase_date: document.getElementById('csvReviewTbody').dataset.purchaseDate || new Date().toISOString().slice(0, 10),
            items,
            source: 'excel',
        },
    });

    btn.disabled = false;
    btn.textContent = 'Confirm & Add to Stock';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    adminToast(res.message || 'Stock updated.', 'success');
    resetCsvImport();

    const productsRes = await adminApi('/products.php?status=active');
    productList = productsRes.success ? productsRes.products : [];

    loadPurchases();
}

// ---------------------------------------------------------
// Manual entry
// ---------------------------------------------------------
function addManualItemRow() {
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
      </div>
      <div class="field-row">
        <div class="field">
          <label>Quantity received *</label>
          <input type="number" class="man-quantity" min="1" step="1" value="1" required>
        </div>
        <div class="field">
          <label>Buy price (cost, per unit) (TSh) *</label>
          <input type="number" class="man-buy-price" min="0" required>
        </div>
      </div>
      <div class="field">
        <label>Selling price (TSh) <span class="man-sell-price-required" style="display:none;color:var(--red);">*</span> <span class="man-sell-price-optional-note" style="color:var(--ink-soft);font-weight:400;">(only needed for a new product)</span></label>
        <input type="number" class="man-sell-price" min="0" placeholder="Leave blank to keep current price">
        <div class="hint man-sell-price-hint"></div>
      </div>
    `;
    wrap.appendChild(row);

    row.querySelector('.remove-row-btn').addEventListener('click', () => row.remove());

    attachProductSearch(
        row.querySelector('.man-product-search'),
        row.querySelector('.product-search-suggestions'),
        () => productList,
        (product) => {
            row.dataset.productId = product.id;
            row.querySelector('.man-sell-price-required').style.display = 'none';
            row.querySelector('.man-sell-price-hint').textContent = `Existing product — current selling price is ${adminFormatMoney(product.price)}. Leave blank to keep it, or enter a new price to change it.`;
        }
    );

    // Typing a name that doesn't match a selected suggestion means "new product".
    row.querySelector('.man-product-search').addEventListener('input', () => {
        delete row.dataset.productId;
        row.querySelector('.man-sell-price-required').style.display = '';
        row.querySelector('.man-sell-price-hint').textContent = 'New product — please provide a selling price for it.';
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
        const quantity = row.querySelector('.man-quantity').value;
        const buyPrice = row.querySelector('.man-buy-price').value;
        const newSellPrice = row.querySelector('.man-sell-price').value;
        const isExisting = !!row.dataset.productId;

        if (!productName || !buyPrice || !quantity) {
            alertBox.innerHTML = `<div class="alert alert-error">Please fill in every item's product name, quantity, and buy price.</div>`;
            return;
        }
        if (!isExisting && !newSellPrice) {
            alertBox.innerHTML = `<div class="alert alert-error">"${adminEscapeHtml(productName)}" is a new product — please provide a selling price for it.</div>`;
            row.querySelector('.man-sell-price').focus();
            return;
        }

        items.push({
            product_id: row.dataset.productId ? Number(row.dataset.productId) : null,
            product_name: productName,
            quantity: Number(quantity),
            buy_price: Number(buyPrice),
            new_sell_price: newSellPrice ? Number(newSellPrice) : null,
        });
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
    document.getElementById('manDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('manualItemsWrap').innerHTML = '';
    manualRowCount = 0;
    addManualItemRow();

    const productsRes = await adminApi('/products.php?status=active');
    productList = productsRes.success ? productsRes.products : [];

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
        const namesPreview = p.items.slice(0, 3).map(i => `${adminEscapeHtml(i.product_name)} (+${i.quantity_units})`).join(', ');
        const more = p.items.length > 3 ? ` +${p.items.length - 3} more` : '';
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
