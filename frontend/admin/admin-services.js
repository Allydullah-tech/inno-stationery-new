/* Services page logic */
let currentAdmin = null;
let searchDebounceTimer = null;

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    currentAdmin = admin;
    renderAdminShell('services', 'Services', admin);

    if (admin.role === 'worker') {
        document.getElementById('newServiceBtn').style.display = 'none';
    }

    loadServices();

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

async function loadServices() {
    const tbody = document.getElementById('servicesTbody');
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const search = document.getElementById('serviceSearch').value.trim();
    const status = document.getElementById('filterServiceStatus').value;
    let query = '?';
    if (search) query += `search=${encodeURIComponent(search)}&`;
    if (status) query += `status=${encodeURIComponent(status)}&`;

    const res = await adminApi('/services.php' + query);
    const services = res.success ? res.services : [];

    renderServiceSummary(res.summary || { total_services: services.length, active_services: services.filter(s => s.status === 'active').length });

    if (!services.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:34px;color:var(--ink-soft);">No services found. ${search || status ? 'Try a different search or filter.' : 'Add your first service to start selling it.'}</td></tr>`;
        return;
    }

    const isWorker = currentAdmin.role === 'worker';

    tbody.innerHTML = services.map(s => `
      <tr>
        <td><strong>${adminEscapeHtml(s.name)}</strong>${s.description ? `<div class="hint" style="margin:2px 0 0;">${adminEscapeHtml(s.description.slice(0, 70))}${s.description.length > 70 ? '…' : ''}</div>` : ''}</td>
        <td>${adminEscapeHtml(s.code || '—')}</td>
        <td class="mono">${adminFormatMoney(s.selling_price)}</td>
        <td class="mono">${s.buying_cost !== null ? adminFormatMoney(s.buying_cost) : '<span style="color:var(--ink-soft);">Not provided</span>'}</td>
        <td><span class="status-pill status-${s.status === 'active' ? 'active' : 'inactive'}">${s.status}</span></td>
        <td>${(s.created_at || '').slice(0, 10)}</td>
        <td>
          ${isWorker ? '' : `
          <div style="display:flex;gap:6px;">
            <button class="icon-btn" onclick='openServiceModal(${JSON.stringify(s).replace(/'/g, "&apos;")})' aria-label="Edit">${adminIcon('edit', 15)}</button>
            <button class="icon-btn danger" onclick="deleteService(${s.id})" aria-label="Delete">${adminIcon('trash', 15)}</button>
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

function openServiceModal(service = null) {
    document.getElementById('serviceFormAlert').innerHTML = '';
    document.getElementById('serviceForm').reset();
    document.getElementById('sf_id').value = service ? service.id : '';
    document.getElementById('serviceModalTitle').textContent = service ? 'Edit Service' : 'Add Service';

    if (service) {
        document.getElementById('sf_name').value = service.name;
        document.getElementById('sf_code').value = service.code || '';
        document.getElementById('sf_status').value = service.status;
        document.getElementById('sf_description').value = service.description || '';
        document.getElementById('sf_selling_price').value = service.selling_price;
        document.getElementById('sf_buying_cost').value = service.buying_cost !== null ? service.buying_cost : '';
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
    const sellingPrice = Number(document.getElementById('sf_selling_price').value);
    const buyingCostRaw = document.getElementById('sf_buying_cost').value;

    if (!document.getElementById('sf_name').value.trim()) {
        alertBox.innerHTML = `<div class="alert alert-error">Please enter a service name.</div>`;
        return;
    }
    if (isNaN(sellingPrice) || sellingPrice < 0) {
        alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid selling price.</div>`;
        return;
    }
    if (buyingCostRaw !== '' && Number(buyingCostRaw) < 0) {
        alertBox.innerHTML = `<div class="alert alert-error">Buying cost cannot be negative.</div>`;
        return;
    }

    const payload = {
        name: document.getElementById('sf_name').value.trim(),
        code: document.getElementById('sf_code').value.trim(),
        description: document.getElementById('sf_description').value.trim(),
        status: document.getElementById('sf_status').value,
        selling_price: sellingPrice,
        buying_cost: buyingCostRaw === '' ? null : Number(buyingCostRaw),
    };

    const btn = document.getElementById('serviceSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    let res;
    if (id) {
        payload.id = Number(id);
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

async function deleteService(id) {
    if (!confirm('Delete this service? Past sales that used it will keep their own record, but it will no longer be selectable for new sales.')) return;
    const res = await adminApi('/services.php?id=' + id, { method: 'DELETE' });
    if (res.success) { adminToast('Service deleted.', 'success'); loadServices(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}
