/* Stock page logic */
let currentAdmin = null;
let adminCategories = [];
let productGroups = [];

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    currentAdmin = admin;
    renderAdminShell('stock', 'Stock', admin);

    // Workers see a read-only view: hide add/edit/delete controls and category management.
    if (admin.role === 'worker') {
        document.getElementById('newProductBtn').style.display = 'none';
        document.getElementById('categoryPanel').style.display = 'none';
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('low_stock') === '1') {
        document.getElementById('filterLowStock').checked = true;
    }

    await loadCategoriesForAdmin();
    loadProductsAdmin();

    document.getElementById('newProductBtn').addEventListener('click', () => openProductModal());
    document.getElementById('productModalClose').addEventListener('click', closeProductModal);
    document.getElementById('productModalBackdrop').addEventListener('click', (e) => { if (e.target.id === 'productModalBackdrop') closeProductModal(); });
    document.getElementById('productForm').addEventListener('submit', saveProduct);
    document.getElementById('addTypeBtn').addEventListener('click', () => addTypeRow());
    document.getElementById('filterCategory').addEventListener('change', loadProductsAdmin);
    document.getElementById('filterLowStock').addEventListener('change', () => { updateDownloadBtn(); loadProductsAdmin(); });
    document.getElementById('stockSearch').addEventListener('input', () => {
        clearTimeout(window._stockSearchTimer);
        window._stockSearchTimer = setTimeout(loadProductsAdmin, 350);
    });
    document.getElementById('newCategoryForm').addEventListener('submit', addCategory);
    document.getElementById('downloadLowStockBtn').addEventListener('click', () => {
        window.open(`${ADMIN_API_BASE}/products.php?low_stock=1&download=csv`, '_blank');
    });
    updateDownloadBtn();
})();

function updateDownloadBtn() {
    document.getElementById('downloadLowStockBtn').style.display = document.getElementById('filterLowStock').checked ? 'inline-flex' : 'none';
}

async function loadCategoriesForAdmin() {
    const res = await adminApi('/categories.php');
    adminCategories = res.success ? res.categories : [];

    const filterSel = document.getElementById('filterCategory');
    filterSel.innerHTML = '<option value="">All categories</option>' + adminCategories.map(c => `<option value="${c.slug}">${adminEscapeHtml(c.name)}</option>`).join('');

    const formSel = document.getElementById('pf_category');
    formSel.innerHTML = '<option value="">— none —</option>' + adminCategories.map(c => `<option value="${c.id}">${adminEscapeHtml(c.name)}</option>`).join('');

    if (currentAdmin.role !== 'worker') {
        document.getElementById('categoryChipsAdmin').innerHTML = adminCategories.map(c => `
          <span class="status-pill status-active" style="display:inline-flex;align-items:center;gap:8px;">
            ${adminEscapeHtml(c.name)} (${c.product_count || 0})
            <button onclick="deleteCategory(${c.id})" style="background:none;border:none;cursor:pointer;color:inherit;font-weight:700;">×</button>
          </span>
        `).join('') || '<p style="color:var(--ink-soft);font-size:13.5px;">No categories yet.</p>';
    }
}

async function addCategory(e) {
    e.preventDefault();
    const name = document.getElementById('newCategoryName').value.trim();
    if (!name) return;
    const res = await adminApi('/categories.php', { method: 'POST', body: { name } });
    if (res.success) {
        document.getElementById('newCategoryName').value = '';
        adminToast('Category added.', 'success');
        loadCategoriesForAdmin();
    } else {
        adminToast(res.message || 'Could not add category.', 'error');
    }
}

async function deleteCategory(id) {
    if (!confirm('Delete this category? Products in it will become uncategorised.')) return;
    const res = await adminApi('/categories.php?id=' + id, { method: 'DELETE' });
    if (res.success) { adminToast('Category deleted.', 'success'); loadCategoriesForAdmin(); loadProductsAdmin(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}

async function loadProductsAdmin() {
    const tbody = document.getElementById('productsTbody');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const cat = document.getElementById('filterCategory').value;
    const lowStock = document.getElementById('filterLowStock').checked;
    const search = document.getElementById('stockSearch').value.trim();
    let query = '?grouped=1&';
    if (cat) query += `category=${encodeURIComponent(cat)}&`;
    if (lowStock) query += `low_stock=1&`;
    if (search) query += `search=${encodeURIComponent(search)}&`;

    const res = await adminApi('/products.php' + query);
    productGroups = res.success ? res.groups : [];

    renderStockSummary(res.summary || { total_products: productGroups.length, total_items: 0, total_value: 0 });

    if (!productGroups.length) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:34px;color:var(--ink-soft);">No products found${search || cat || lowStock ? ' for the current filters' : ''}.</td></tr>`;
        return;
    }

    const isWorker = currentAdmin.role === 'worker';

    tbody.innerHTML = productGroups.map(g => `
      <tr>
        <td><strong>${adminEscapeHtml(g.name)}</strong></td>
        <td>${adminEscapeHtml(g.category_name || '—')}</td>
        <td><div class="type-list">${g.types.map(t => `<span class="type-chip${t.is_low_stock ? ' low' : ''}" title="Buy ${adminFormatMoney(t.buy_price)} · Sell ${adminFormatMoney(t.price)}">${adminEscapeHtml(t.variant || 'Standard')} · ${t.stock}${t.unit && t.unit !== 'pcs' ? ' ' + adminEscapeHtml(t.unit) : ''}</span>`).join('')}</div></td>
        <td>${g.total_stock}${g.has_low_stock ? ' <span class="status-pill status-low">Low</span>' : ''}</td>
        <td><span class="status-pill status-${g.status === 'active' ? 'active' : 'inactive'}">${g.status}</span></td>
        <td>
          ${isWorker ? '' : `
          <div style="display:flex;gap:6px;">
            <button class="icon-btn" onclick="openProductModal(${g.group_id})" aria-label="Edit">${adminIcon('edit', 15)}</button>
            <button class="icon-btn danger" onclick="deleteProduct(${g.group_id})" aria-label="Delete">${adminIcon('trash', 15)}</button>
          </div>`}
        </td>
      </tr>
    `).join('');
}

function renderStockSummary(summary) {
    document.getElementById('stockSummaryGrid').innerHTML = `
      <div class="summary-card">
        <div class="dsc-label">Total Stock Value</div>
        <div class="dsc-value">${adminFormatMoney(summary.total_value)}</div>
      </div>
      <div class="summary-card">
        <div class="dsc-label">Total Products</div>
        <div class="dsc-value">${summary.total_products}</div>
      </div>
      <div class="summary-card">
        <div class="dsc-label">Total Items</div>
        <div class="dsc-value">${summary.total_items}</div>
      </div>
    `;
}

function addTypeRow(t = {}) {
    const row = document.createElement('div');
    row.className = 'type-row';
    row.dataset.id = t.id || '';
    row.innerHTML = `
      <input type="text" class="tr-variant" placeholder="e.g. Blue" value="${adminEscapeHtml(t.variant || '')}">
      <input type="number" class="tr-buy" min="0" step="1" required placeholder="Buy" value="${t.buy_price ?? ''}">
      <input type="number" class="tr-price" min="0" step="1" required placeholder="Sell" value="${t.price ?? ''}">
      <input type="number" class="tr-min" min="0" step="1" placeholder="Min" title="Lowest price a cashier may sell at (optional)" value="${t.minimum_price ?? ''}">
      <input type="number" class="tr-stock" min="0" step="1" required placeholder="Qty" value="${t.stock ?? ''}">
      <input type="number" class="tr-low" min="0" step="1" value="${t.low_stock_threshold ?? 5}">
      <button type="button" class="icon-btn danger" aria-label="Remove type">${adminIcon('trash', 14)}</button>`;
    row.querySelector('button').addEventListener('click', () => {
        if (document.querySelectorAll('#pf_types .type-row').length > 1) row.remove();
        else adminToast('A product needs at least one type.', 'error');
    });
    document.getElementById('pf_types').appendChild(row);
}

function openProductModal(groupId = null) {
    const group = groupId ? productGroups.find(g => g.group_id === groupId) : null;
    document.getElementById('productFormAlert').innerHTML = '';
    document.getElementById('productForm').reset();
    document.getElementById('pf_id').value = group ? group.group_id : '';
    document.getElementById('productModalTitle').textContent = group ? 'Edit Product' : 'Add Product';
    document.getElementById('pf_types').innerHTML = '';

    if (group) {
        document.getElementById('pf_name').value = group.name;
        document.getElementById('pf_description').value = group.description || '';
        document.getElementById('pf_category').value = group.category_id || '';
        document.getElementById('pf_status').value = group.status;
        group.types.forEach(t => addTypeRow(t));
    } else {
        addTypeRow();
    }

    document.getElementById('productModalBackdrop').classList.add('open');
}
function closeProductModal() {
    document.getElementById('productModalBackdrop').classList.remove('open');
}

async function saveProduct(e) {
    e.preventDefault();
    const alertBox = document.getElementById('productFormAlert');
    alertBox.innerHTML = '';

    const id = document.getElementById('pf_id').value;
    const payload = {
        name: document.getElementById('pf_name').value.trim(),
        description: document.getElementById('pf_description').value.trim(),
        category_id: document.getElementById('pf_category').value || null,
        status: document.getElementById('pf_status').value,
        types: [...document.querySelectorAll('#pf_types .type-row')].map(r => ({
            id: r.dataset.id ? Number(r.dataset.id) : 0,
            variant: r.querySelector('.tr-variant').value.trim(),
            buy_price: r.querySelector('.tr-buy').value,
            price: r.querySelector('.tr-price').value,
            stock: r.querySelector('.tr-stock').value,
            low_stock_threshold: r.querySelector('.tr-low').value,
            minimum_price: r.querySelector('.tr-min').value,
        })),
    };

    const btn = document.getElementById('productSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    let res;
    if (id) {
        payload.group_id = Number(id);
        res = await adminApi('/products.php', { method: 'PUT', body: payload });
    } else {
        res = await adminApi('/products.php', { method: 'POST', body: payload });
    }

    btn.disabled = false;
    btn.textContent = 'Save Product';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message || 'Could not save product.')}</div>`;
        return;
    }

    adminToast(id ? 'Product updated.' : 'Product added.', 'success');
    closeProductModal();
    loadProductsAdmin();
    loadCategoriesForAdmin();
}

async function deleteProduct(groupId) {
    if (!confirm('Delete this product and all its types? This cannot be undone.')) return;
    const res = await adminApi('/products.php?group_id=' + groupId, { method: 'DELETE' });
    if (res.success) { adminToast('Product deleted.', 'success'); loadProductsAdmin(); loadCategoriesForAdmin(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}
