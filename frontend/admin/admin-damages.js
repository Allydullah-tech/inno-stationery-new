/* Damages page logic — stock items + non-stock assets */
let currentAdmin = null;
let productList = [];
let selectedProduct = null;

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    currentAdmin = admin;
    renderAdminShell('damages', 'Damages', admin);

    document.getElementById('dmgDate').value = new Date().toISOString().slice(0, 10);

    await loadProductsForSearch();
    loadDamages();

    attachProductSearch(
        document.getElementById('dmgProductSearch'),
        document.getElementById('dmgProductSuggestions'),
        () => productList,
        (product) => {
            selectedProduct = product;
            document.getElementById('dmgStockHint').textContent = `${product.stock} currently in stock.`;
        }
    );

    document.querySelectorAll('input[name="stockScope"]').forEach(r => r.addEventListener('change', updateScopeVisibility));
    updateScopeVisibility();

    document.getElementById('damageForm').addEventListener('submit', recordDamage);
    document.getElementById('filterBtn').addEventListener('click', loadDamages);
    document.getElementById('dmgSearch').addEventListener('input', () => {
        clearTimeout(window._dmgSearchTimer);
        window._dmgSearchTimer = setTimeout(loadDamages, 350);
    });
    document.getElementById('filterScope').addEventListener('change', loadDamages);
})();

function updateScopeVisibility() {
    const isStock = document.querySelector('input[name="stockScope"]:checked').value === 'stock';
    document.getElementById('stockItemWrap').style.display = isStock ? '' : 'none';
    document.getElementById('nonStockItemWrap').style.display = isStock ? 'none' : '';
}

async function loadProductsForSearch() {
    const res = await adminApi('/products.php?status=active');
    productList = res.success ? res.products : [];
}

async function recordDamage(e) {
    e.preventDefault();
    const alertBox = document.getElementById('damageAlert');
    alertBox.innerHTML = '';

    const isStock = document.querySelector('input[name="stockScope"]:checked').value === 'stock';
    const payload = {
        is_stock_item: isStock,
        reason: document.querySelector('input[name="dmgReason"]:checked').value,
        damage_date: document.getElementById('dmgDate').value,
        notes: document.getElementById('dmgNotes').value.trim(),
    };

    if (isStock) {
        const searchInput = document.getElementById('dmgProductSearch');
        const selectedId = searchInput.dataset.selectedId;
        if (!selectedId || !selectedProduct || selectedProduct.name !== searchInput.value) {
            alertBox.innerHTML = `<div class="alert alert-error">Please choose a product from the suggestions list.</div>`;
            return;
        }
        const quantity = Number(document.getElementById('dmgQty').value);
        if (!quantity || quantity <= 0) {
            alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid quantity.</div>`;
            return;
        }
        payload.product_id = Number(selectedId);
        payload.quantity = quantity;
    } else {
        const itemName = document.getElementById('dmgItemName').value.trim();
        const quantity = Number(document.getElementById('dmgItemQty').value);
        const value = document.getElementById('dmgItemValue').value;
        if (!itemName) {
            alertBox.innerHTML = `<div class="alert alert-error">Please enter the item name.</div>`;
            return;
        }
        if (!quantity || quantity <= 0) {
            alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid quantity.</div>`;
            return;
        }
        if (value === '' || Number(value) < 0) {
            alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid estimated/actual value.</div>`;
            return;
        }
        payload.item_name = itemName;
        payload.category = document.getElementById('dmgCategory').value.trim();
        payload.quantity = quantity;
        payload.unit_cost = Number(value);
    }

    const btn = document.getElementById('damageSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    const res = await adminApi('/damages.php', { method: 'POST', body: payload });

    btn.disabled = false;
    btn.textContent = 'Record';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    adminToast('Loss recorded.', 'success');
    document.getElementById('damageForm').reset();
    document.getElementById('dmgDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('dmgStockHint').textContent = '';
    document.getElementById('dmgProductSearch').dataset.selectedId = '';
    selectedProduct = null;
    updateScopeVisibility();
    loadProductsForSearch();
    loadDamages();
}

async function loadDamages() {
    const tbody = document.getElementById('damagesTbody');
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    const search = document.getElementById('dmgSearch').value.trim();
    const scope = document.getElementById('filterScope').value;
    let query = '?limit=150&';
    if (from) query += `from=${from}&`;
    if (to) query += `to=${to}&`;
    if (search) query += `search=${encodeURIComponent(search)}&`;
    if (scope) query += `scope=${scope}&`;

    const res = await adminApi('/damages.php' + query);
    const damages = res.success ? res.damages : [];

    if (!damages.length) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:34px;color:var(--ink-soft);">No damage or loss records found${search || scope || from || to ? ' for the current filters' : ' yet'}.</td></tr>`;
        return;
    }

    const isWorker = currentAdmin.role === 'worker';

    tbody.innerHTML = damages.map(d => `
      <tr>
        <td>${d.damage_date}</td>
        <td>${adminEscapeHtml(d.product_name)}</td>
        <td>${d.is_stock_item ? '<span class="status-pill status-product">Stock</span>' : '<span class="status-pill status-inactive">Non-Stock</span>'}</td>
        <td>${adminEscapeHtml(d.category || '—')}</td>
        <td>${d.quantity}</td>
        <td style="text-transform:capitalize;">${d.reason}</td>
        <td class="mono">${adminFormatMoney(d.loss_amount)}</td>
        <td>${adminEscapeHtml(d.recorded_by_name || '—')}</td>
        <td>${isWorker ? '' : `<button class="icon-btn danger" onclick="deleteDamage(${d.id})" aria-label="Delete">${adminIcon('trash', 14)}</button>`}</td>
      </tr>
    `).join('');
}

async function deleteDamage(id) {
    if (!confirm('Delete this record? Stock will be restored only if it was a stock item.')) return;
    const res = await adminApi('/damages.php?id=' + id, { method: 'DELETE' });
    if (res.success) { adminToast('Record deleted.', 'success'); loadDamages(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}
