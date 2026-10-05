/* Stock page logic */
let currentAdmin = null;
let adminCategories = [];

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
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const cat = document.getElementById('filterCategory').value;
    const lowStock = document.getElementById('filterLowStock').checked;
    const search = document.getElementById('stockSearch').value.trim();
    let query = '?';
    if (cat) query += `category=${encodeURIComponent(cat)}&`;
    if (lowStock) query += `low_stock=1&`;
    if (search) query += `search=${encodeURIComponent(search)}&`;

    const res = await adminApi('/products.php' + query);
    const products = res.success ? res.products : [];

    renderStockSummary(res.summary || { total_products: products.length, total_items: 0, total_value: 0 });

    if (!products.length) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:34px;color:var(--ink-soft);">No products found${search || cat || lowStock ? ' for the current filters' : ''}.</td></tr>`;
        return;
    }

    const isWorker = currentAdmin.role === 'worker';

    tbody.innerHTML = products.map(p => `
      <tr>
        <td><img src="${p.image_url || '../assets/placeholder.svg'}" class="table-thumb" onerror="this.src='../assets/placeholder.svg'"></td>
        <td><strong>${adminEscapeHtml(p.name)}</strong></td>
        <td>${adminEscapeHtml(p.category_name || '—')}</td>
        <td class="mono">${adminFormatMoney(p.buy_price)}</td>
        <td class="mono">${adminFormatMoney(p.price)}</td>
        <td>${p.stock}${p.is_low_stock ? ' <span class="status-pill status-low">Low</span>' : ''}</td>
        <td><span class="status-pill status-${p.status === 'active' ? 'active' : 'inactive'}">${p.status}</span></td>
        <td>
          ${isWorker ? '' : `
          <div style="display:flex;gap:6px;">
            <button class="icon-btn" onclick='openProductModal(${JSON.stringify(p).replace(/'/g, "&apos;")})' aria-label="Edit">${adminIcon('edit', 15)}</button>
            <button class="icon-btn danger" onclick="deleteProduct(${p.id})" aria-label="Delete">${adminIcon('trash', 15)}</button>
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

function openProductModal(product = null) {
    document.getElementById('productFormAlert').innerHTML = '';
    document.getElementById('productForm').reset();
    document.getElementById('pf_id').value = product ? product.id : '';
    document.getElementById('productModalTitle').textContent = product ? 'Edit Product' : 'Add Product';

    if (product) {
        document.getElementById('pf_name').value = product.name;
        document.getElementById('pf_description').value = product.description || '';
        document.getElementById('pf_buy_price').value = product.buy_price;
        document.getElementById('pf_price').value = product.price;
        document.getElementById('pf_stock').value = product.stock;
        document.getElementById('pf_low_stock_threshold').value = product.low_stock_threshold;
        document.getElementById('pf_category').value = product.category_id || '';
        document.getElementById('pf_status').value = product.status;
    } else {
        document.getElementById('pf_low_stock_threshold').value = 5;
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
    const fd = new FormData();
    fd.append('name', document.getElementById('pf_name').value.trim());
    fd.append('description', document.getElementById('pf_description').value.trim());
    fd.append('buy_price', document.getElementById('pf_buy_price').value);
    fd.append('price', document.getElementById('pf_price').value);
    fd.append('stock', document.getElementById('pf_stock').value);
    fd.append('low_stock_threshold', document.getElementById('pf_low_stock_threshold').value);
    fd.append('category_id', document.getElementById('pf_category').value);
    fd.append('status', document.getElementById('pf_status').value);
    const imgFile = document.getElementById('pf_image').files[0];
    if (imgFile) fd.append('image', imgFile);

    const btn = document.getElementById('productSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    let res;
    if (id) {
        fd.append('id', id);
        fd.append('_method', 'PUT');
        res = await adminApi('/products.php', { method: 'POST', body: fd, isForm: true });
    } else {
        res = await adminApi('/products.php', { method: 'POST', body: fd, isForm: true });
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

async function deleteProduct(id) {
    if (!confirm('Delete this product? This cannot be undone.')) return;
    const res = await adminApi('/products.php?id=' + id, { method: 'DELETE' });
    if (res.success) { adminToast('Product deleted.', 'success'); loadProductsAdmin(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}
