/* Services page logic */
let currentAdmin = null;
let searchDebounceTimer = null;
let serviceGroups = [];

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    currentAdmin = admin;
    renderAdminShell('services', 'Services', admin);

    if (admin.role === 'worker') {
        document.getElementById('newServiceBtn').style.display = 'none';
    }

    await loadCategories();
    loadServices();

    document.getElementById('addServiceTypeBtn').addEventListener('click', () => addServiceTypeRow());
    document.getElementById('filterServiceCategory').addEventListener('change', loadServices);
    document.getElementById('serviceSearch').addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(loadServices, 300);
    });
    document.getElementById('filterServiceStatus').addEventListener('change', loadServices);

    document.getElementById('newServiceBtn').addEventListener('click', () => openServiceModal());
    document.getElementById('serviceModalClose').addEventListener('click', closeServiceModal);
    document.getElementById('serviceModalBackdrop').addEventListener('click', (e) => {
        if (e.target.id === 'serviceModalBackdrop') closeServiceModal();
    });
    document.getElementById('serviceForm').addEventListener('submit', saveService);
})();

async function loadCategories() {
    const res = await adminApi('/categories.php');
    const cats = res.success ? res.categories : [];
    document.getElementById('filterServiceCategory').innerHTML = '<option value="">All categories</option>' + cats.map(c => `<option value="${c.slug}">${adminEscapeHtml(c.name)}</option>`).join('');
    document.getElementById('sf_category').innerHTML = '<option value="">— none —</option>' + cats.map(c => `<option value="${c.id}">${adminEscapeHtml(c.name)}</option>`).join('');
}

async function loadServices() {
    const tbody = document.getElementById('servicesTbody');
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const search = document.getElementById('serviceSearch').value.trim();
    const status = document.getElementById('filterServiceStatus').value;
    const cat = document.getElementById('filterServiceCategory').value;
    let query = '?grouped=1&';
    if (search) query += `search=${encodeURIComponent(search)}&`;
    if (status) query += `status=${encodeURIComponent(status)}&`;
    if (cat) query += `category=${encodeURIComponent(cat)}&`;

    const res = await adminApi('/services.php' + query);
    serviceGroups = res.success ? res.groups : [];

    renderServiceSummary(res.summary || { total_services: serviceGroups.length, active_services: serviceGroups.filter(s => s.status === 'active').length });

    if (!serviceGroups.length) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:34px;color:var(--ink-soft);">No services found. ${search || status || cat ? 'Try a different search or filter.' : 'Add your first service to start selling it.'}</td></tr>`;
        return;
    }

    const isWorker = currentAdmin.role === 'worker';

    tbody.innerHTML = serviceGroups.map(g => `
      <tr>
        <td><strong>${adminEscapeHtml(g.name)}</strong>${g.description ? `<div class="hint" style="margin:2px 0 0;">${adminEscapeHtml(g.description.slice(0, 70))}${g.description.length > 70 ? '…' : ''}</div>` : ''}</td>
        <td>${adminEscapeHtml(g.category_name || '—')}</td>
        <td>${g.types.map(t => `<span class="type-chip">${adminEscapeHtml(t.variant || 'Standard')} · ${adminFormatMoney(t.selling_price)}</span>`).join('')}</td>
        <td><span class="status-pill status-${g.status === 'active' ? 'active' : 'inactive'}">${g.status}</span></td>
        <td>
          ${isWorker ? '' : `
          <div style="display:flex;gap:6px;">
            <button class="icon-btn" onclick="openServiceModal(${g.group_id})" aria-label="Edit">${adminIcon('edit', 15)}</button>
            <button class="icon-btn danger" onclick="deleteService(${g.group_id})" aria-label="Delete">${adminIcon('trash', 15)}</button>
          </div>`}
        </td>
      </tr>
    `).join('');
}

function renderServiceSummary(summary) {
    document.getElementById('serviceSummaryGrid').innerHTML = `
      <div class="summary-card">
        <div class="dsc-label">Total Services</div>
        <div class="dsc-value">${summary.total_services}</div>
      </div>
      <div class="summary-card good">
        <div class="dsc-label">Active Services</div>
        <div class="dsc-value">${summary.active_services}</div>
      </div>
    `;
}

function addServiceTypeRow(t = {}) {
    const row = document.createElement('div');
    row.className = 'type-row';
    row.dataset.id = t.id || '';
    row.innerHTML = `
      <input type="text" class="tr-variant" placeholder="e.g. Colour A4" value="${adminEscapeHtml(t.variant || '')}">
      <input type="number" class="tr-price" min="0" step="1" required placeholder="Price" value="${t.selling_price ?? ''}">
      <input type="number" class="tr-min" min="0" step="1" placeholder="Min" title="Lowest price a cashier may charge (optional)" value="${t.minimum_price ?? ''}">
      <button type="button" class="icon-btn danger" aria-label="Remove type">${adminIcon('trash', 14)}</button>`;
    row.querySelector('button').addEventListener('click', () => {
        if (document.querySelectorAll('#sf_types .type-row').length > 1) row.remove();
        else adminToast('A service needs at least one type.', 'error');
    });
    document.getElementById('sf_types').appendChild(row);
}

function openServiceModal(groupId = null) {
    const group = groupId ? serviceGroups.find(g => g.group_id === groupId) : null;
    document.getElementById('serviceFormAlert').innerHTML = '';
    document.getElementById('serviceForm').reset();
    document.getElementById('sf_id').value = group ? group.group_id : '';
    document.getElementById('serviceModalTitle').textContent = group ? 'Edit Service' : 'Add Service';
    document.getElementById('sf_types').innerHTML = '';

    if (group) {
        document.getElementById('sf_name').value = group.name;
        document.getElementById('sf_category').value = group.category_id || '';
        document.getElementById('sf_status').value = group.status;
        document.getElementById('sf_description').value = group.description || '';
        group.types.forEach(t => addServiceTypeRow(t));
    } else {
        addServiceTypeRow();
    }

    document.getElementById('serviceModalBackdrop').classList.add('open');
}
function closeServiceModal() {
    document.getElementById('serviceModalBackdrop').classList.remove('open');
}

async function saveService(e) {
    e.preventDefault();
    const alertBox = document.getElementById('serviceFormAlert');
    alertBox.innerHTML = '';

    const id = document.getElementById('sf_id').value;
    if (!document.getElementById('sf_name').value.trim()) {
        alertBox.innerHTML = `<div class="alert alert-error">Please enter a service name.</div>`;
        return;
    }

    const payload = {
        name: document.getElementById('sf_name').value.trim(),
        description: document.getElementById('sf_description').value.trim(),
        category_id: document.getElementById('sf_category').value || null,
        status: document.getElementById('sf_status').value,
        types: [...document.querySelectorAll('#sf_types .type-row')].map(r => ({
            id: r.dataset.id ? Number(r.dataset.id) : 0,
            variant: r.querySelector('.tr-variant').value.trim(),
            selling_price: r.querySelector('.tr-price').value,
            minimum_price: r.querySelector('.tr-min').value,
        })),
    };

    const btn = document.getElementById('serviceSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    let res;
    if (id) {
        payload.group_id = Number(id);
        res = await adminApi('/services.php', { method: 'PUT', body: payload });
    } else {
        res = await adminApi('/services.php', { method: 'POST', body: payload });
    }

    btn.disabled = false;
    btn.textContent = 'Save Service';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    adminToast(id ? 'Service updated.' : 'Service added.', 'success');
    closeServiceModal();
    loadServices();
}

async function deleteService(groupId) {
    if (!confirm('Delete this service and all its types? Past sales that used it will keep their own record, but it will no longer be selectable for new sales.')) return;
    const res = await adminApi('/services.php?group_id=' + groupId, { method: 'DELETE' });
    if (res.success) { adminToast('Service deleted.', 'success'); loadServices(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}
