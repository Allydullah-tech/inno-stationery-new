/* Sales page logic — product/service selling, cash & credit workflow, edit + delete */
let currentAdmin = null;
let productList = [];
let serviceList = [];
let selectedProduct = null;
let selectedService = null;
let pendingSalePayload = null; // built on submit, sent to the API only after modal confirmation

let editSelectedProduct = null;
let editSelectedService = null;

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    currentAdmin = admin;
    renderAdminShell('sales', 'Sales', admin);

    document.getElementById('saleDate').value = new Date().toISOString().slice(0, 10);

    await loadItemsForSearch();
    loadSales();

    // ---- Create form: item type toggle ----
    document.querySelectorAll('input[name="itemType"]').forEach(r => r.addEventListener('change', updateItemTypeVisibility));
    attachProductSearch(
        document.getElementById('saleProductSearch'), document.getElementById('saleProductSuggestions'),
        () => productList,
        (product) => {
            selectedProduct = product;
            document.getElementById('saleUnitPrice').value = product.price;
            document.getElementById('saleStockHint').textContent = `${product.stock} currently in stock.`;
            updateCreditAmountDisplay();
        }
    );
    attachServiceSearch(
        document.getElementById('saleServiceSearch'), document.getElementById('saleServiceSuggestions'),
        () => serviceList,
        (service) => {
            selectedService = service;
            document.getElementById('saleUnitPrice').value = service.selling_price;
            document.getElementById('saleServiceHint').textContent = service.description ? service.description.slice(0, 90) : 'Default price shown — edit above to discount.';
            updateCreditAmountDisplay();
        }
    );

    // ---- Sale type: cash vs credit ----
    document.querySelectorAll('input[name="saleType"]').forEach(r => r.addEventListener('change', updateWorkflowVisibility));
    document.querySelectorAll('input[name="paymentChannel"]').forEach(r => r.addEventListener('change', updateWorkflowVisibility));

    document.getElementById('saleQty').addEventListener('input', updateCreditAmountDisplay);
    document.getElementById('saleServiceQty').addEventListener('input', () => {
        document.getElementById('saleQty').value = document.getElementById('saleServiceQty').value;
        updateCreditAmountDisplay();
    });
    document.getElementById('saleUnitPrice').addEventListener('input', updateCreditAmountDisplay);

    updateItemTypeVisibility();
    updateWorkflowVisibility();

    document.getElementById('saleForm').addEventListener('submit', onSaleFormSubmit);
    document.getElementById('filterBtn').addEventListener('click', loadSales);
    document.getElementById('salesSearch').addEventListener('input', () => {
        clearTimeout(window._salesSearchTimer);
        window._salesSearchTimer = setTimeout(loadSales, 350);
    });
    document.getElementById('filterItemType').addEventListener('change', loadSales);

    document.getElementById('saleConfirmClose').addEventListener('click', closeSaleConfirm);
    document.getElementById('saleConfirmBackdrop').addEventListener('click', (e) => {
        if (e.target === document.getElementById('saleConfirmBackdrop')) closeSaleConfirm();
    });
    document.getElementById('saleConfirmBtn').addEventListener('click', submitPendingSale);

    // ---- Edit modal wiring ----
    document.querySelectorAll('input[name="e_itemType"]').forEach(r => r.addEventListener('change', updateEditItemTypeVisibility));
    document.querySelectorAll('input[name="e_saleType"]').forEach(r => r.addEventListener('change', updateEditWorkflowVisibility));
    document.querySelectorAll('input[name="e_paymentChannel"]').forEach(r => r.addEventListener('change', updateEditWorkflowVisibility));
    attachProductSearch(
        document.getElementById('e_productSearch'), document.getElementById('e_productSuggestions'),
        () => productList,
        (product) => {
            editSelectedProduct = product;
            document.getElementById('e_unitPrice').value = product.price;
            document.getElementById('e_stockHint').textContent = `${product.stock} currently in stock.`;
        }
    );
    attachServiceSearch(
        document.getElementById('e_serviceSearch'), document.getElementById('e_serviceSuggestions'),
        () => serviceList,
        (service) => {
            editSelectedService = service;
            document.getElementById('e_unitPrice').value = service.selling_price;
        }
    );
    document.getElementById('e_serviceQty').addEventListener('input', () => {
        document.getElementById('e_qty').value = document.getElementById('e_serviceQty').value;
    });
    document.getElementById('editSaleClose').addEventListener('click', closeEditSaleModal);
    document.getElementById('editSaleBackdrop').addEventListener('click', (e) => {
        if (e.target === document.getElementById('editSaleBackdrop')) closeEditSaleModal();
    });
    document.getElementById('editSaleForm').addEventListener('submit', submitEditSale);
})();

function updateItemTypeVisibility() {
    const isProduct = document.querySelector('input[name="itemType"]:checked').value === 'product';
    document.getElementById('productPickWrap').style.display = isProduct ? '' : 'none';
    document.getElementById('servicePickWrap').style.display = isProduct ? 'none' : '';
    document.getElementById('saleQty').required = isProduct;
    document.getElementById('saleServiceQty').required = !isProduct;
    if (!isProduct) document.getElementById('saleServiceQty').value = document.getElementById('saleQty').value || 1;
}

function updateWorkflowVisibility() {
    const saleType = document.querySelector('input[name="saleType"]:checked').value;
    const isCash = saleType === 'cash';
    document.getElementById('cashPaymentSection').style.display = isCash ? '' : 'none';
    document.getElementById('creditSection').style.display = isCash ? 'none' : '';
    if (!isCash) { updateCreditAmountDisplay(); return; }
    const channel = document.querySelector('input[name="paymentChannel"]:checked').value;
    document.getElementById('onlineMethodSection').style.display = channel === 'online' ? '' : 'none';
}

function updateCreditAmountDisplay() {
    const isProduct = document.querySelector('input[name="itemType"]:checked').value === 'product';
    const qty = Number((isProduct ? document.getElementById('saleQty') : document.getElementById('saleServiceQty')).value) || 0;
    const price = Number(document.getElementById('saleUnitPrice').value) || 0;
    document.getElementById('creditAmountDisplay').textContent = adminFormatMoney(qty * price);
}

async function loadItemsForSearch() {
    const [pRes, sRes] = await Promise.all([
        adminApi('/products.php?status=active'),
        adminApi('/services.php?status=active'),
    ]);
    productList = pRes.success ? pRes.products : [];
    serviceList = sRes.success ? sRes.services : [];
}

/** Validate the form, build the payload + a human-readable summary, then open the confirm modal. */
function onSaleFormSubmit(e) {
    e.preventDefault();
    const alertBox = document.getElementById('saleAlert');
    alertBox.innerHTML = '';

    const isProduct = document.querySelector('input[name="itemType"]:checked').value === 'product';
    const unitPrice = Number(document.getElementById('saleUnitPrice').value);
    const saleDate = document.getElementById('saleDate').value;
    const notes = document.getElementById('saleNotes').value.trim();
    const saleType = document.querySelector('input[name="saleType"]:checked').value;

    let quantity, itemLabel;
    const payload = { sale_date: saleDate, notes, sale_type: saleType, unit_price: unitPrice };

    if (isProduct) {
        const searchInput = document.getElementById('saleProductSearch');
        const selectedId = searchInput.dataset.selectedId;
        if (!selectedId || !selectedProduct || selectedProduct.name !== searchInput.value) {
            alertBox.innerHTML = `<div class="alert alert-error">Please choose a product from the suggestions list.</div>`;
            return;
        }
        quantity = Number(document.getElementById('saleQty').value);
        payload.item_type = 'product';
        payload.product_id = Number(selectedId);
        itemLabel = selectedProduct.name;
    } else {
        const searchInput = document.getElementById('saleServiceSearch');
        const selectedId = searchInput.dataset.selectedId;
        if (!selectedId || !selectedService || selectedService.name !== searchInput.value) {
            alertBox.innerHTML = `<div class="alert alert-error">Please choose a service from the suggestions list.</div>`;
            return;
        }
        quantity = Number(document.getElementById('saleServiceQty').value);
        payload.item_type = 'service';
        payload.service_id = Number(selectedId);
        itemLabel = selectedService.name;
    }

    if (!quantity || quantity <= 0) {
        alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid quantity.</div>`;
        return;
    }
    if (isNaN(unitPrice) || unitPrice < 0) {
        alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid selling price.</div>`;
        return;
    }
    payload.quantity = quantity;

    const summaryRows = [
        [isProduct ? 'Product' : 'Service', adminEscapeHtml(itemLabel)],
        ['Quantity', quantity],
        ['Unit Price', adminFormatMoney(unitPrice)],
        ['Total Amount', adminFormatMoney(quantity * unitPrice)],
    ];

    if (saleType === 'cash') {
        const channel = document.querySelector('input[name="paymentChannel"]:checked').value;
        let paymentMethod = 'cash_in_hand';
        if (channel === 'online') paymentMethod = document.querySelector('input[name="mobileMoneyMethod"]:checked').value;
        payload.payment_method = paymentMethod;
        summaryRows.push(['Sale Type', 'Cash Sale']);
        summaryRows.push(['Payment Method', paymentMethodLabel(paymentMethod)]);
    } else {
        const custName = document.getElementById('custName').value.trim();
        const custPhone = document.getElementById('custPhone').value.trim();
        const custDeadline = document.getElementById('custDeadline').value;
        const custNotes = document.getElementById('custNotes').value.trim();

        if (!custName || !custPhone || !custDeadline) {
            alertBox.innerHTML = `<div class="alert alert-error">Please fill in customer name, phone number, and payment deadline for this credit sale.</div>`;
            return;
        }
        payload.customer_name = custName;
        payload.customer_phone = custPhone;
        payload.payment_deadline = custDeadline;
        payload.credit_notes = custNotes;

        summaryRows.push(['Sale Type', 'Sale on Credit']);
        summaryRows.push(['Customer', adminEscapeHtml(custName)]);
        summaryRows.push(['Phone', adminEscapeHtml(custPhone)]);
        summaryRows.push(['Payment Deadline', custDeadline]);
    }

    pendingSalePayload = payload;
    openSaleConfirm(summaryRows, saleType);
}

function openSaleConfirm(rows, saleType) {
    document.getElementById('saleConfirmTitle').textContent = 'Confirm Sale';
    document.getElementById('saleConfirmBtn').textContent = 'Confirm & Complete Sale';
    document.getElementById('saleConfirmGrid').innerHTML = rows.map(([label, value]) => `
      <div class="ddg-item"><div class="ddg-label">${label}</div><div class="ddg-value">${value}</div></div>
    `).join('');
    document.getElementById('saleConfirmAlert').innerHTML = saleType === 'credit'
        ? `<div class="alert alert-info">This will create an outstanding debt for the customer. You can track and record payments from the Debts / Credit page.</div>`
        : '';
    document.getElementById('saleConfirmBackdrop').classList.add('open');
}
function closeSaleConfirm() {
    document.getElementById('saleConfirmBackdrop').classList.remove('open');
    pendingSalePayload = null;
}

async function submitPendingSale() {
    if (!pendingSalePayload) return;
    const btn = document.getElementById('saleConfirmBtn');
    const alertBox = document.getElementById('saleConfirmAlert');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    const res = await adminApi('/sales.php', { method: 'POST', body: pendingSalePayload });

    btn.disabled = false;
    btn.textContent = 'Confirm & Complete Sale';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    closeSaleConfirm();
    adminToast(res.message || 'Sale recorded.', 'success');
    resetSaleForm();
    loadItemsForSearch();
    loadSales();
}

function resetSaleForm() {
    document.getElementById('saleForm').reset();
    document.getElementById('saleDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('saleStockHint').textContent = '';
    document.getElementById('saleServiceHint').textContent = '';
    document.getElementById('saleProductSearch').dataset.selectedId = '';
    document.getElementById('saleServiceSearch').dataset.selectedId = '';
    selectedProduct = null;
    selectedService = null;
    updateItemTypeVisibility();
    updateWorkflowVisibility();
    updateCreditAmountDisplay();
}

async function loadSales() {
    const tbody = document.getElementById('salesTbody');
    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    const search = document.getElementById('salesSearch').value.trim();
    const itemType = document.getElementById('filterItemType').value;
    let query = '?limit=150&';
    if (from) query += `from=${from}&`;
    if (to) query += `to=${to}&`;
    if (search) query += `search=${encodeURIComponent(search)}&`;
    if (itemType) query += `item_type=${itemType}&`;

    const res = await adminApi('/sales.php' + query);
    const sales = res.success ? res.sales : [];

    if (!sales.length) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:34px;color:var(--ink-soft);">No sales found${search || itemType || from || to ? ' for the current filters' : ' yet'}.</td></tr>`;
        return;
    }

    const isWorker = currentAdmin.role === 'worker';

    tbody.innerHTML = sales.map(s => {
        const typeBadge = s.sale_type === 'credit'
            ? `<span class="status-pill status-partial">Credit</span>`
            : `<span class="status-pill status-active">Cash</span>`;
        const itemTypeBadge = s.item_type === 'service'
            ? `<span class="status-pill status-service">Service</span>`
            : `<span class="status-pill status-product">Product</span>`;
        const paymentCell = s.sale_type === 'credit'
            ? (s.debt_status ? debtStatusPill(s.debt_status) : '—')
            : paymentMethodLabel(s.payment_method);
        const priceCell = s.has_discount
            ? `<span class="list-price-strike">${adminFormatMoney(s.list_price)}</span>${adminFormatMoney(s.unit_price)}<span class="discount-tag">Discount</span>`
            : adminFormatMoney(s.unit_price);
        const hasPayments = s.sale_type === 'credit' && s.debt_amount_paid !== undefined && Number(s.debt_amount_paid) > 0;

        return `
      <tr>
        <td>${s.sale_date}</td>
        <td>${adminEscapeHtml(s.product_name)}</td>
        <td>${itemTypeBadge}</td>
        <td>${s.quantity}</td>
        <td class="mono">${priceCell}</td>
        <td class="mono">${adminFormatMoney(s.total_amount)}</td>
        <td>${typeBadge}</td>
        <td>${paymentCell}</td>
        <td>${adminEscapeHtml(s.recorded_by_name || '—')}</td>
        <td>
          ${isWorker ? '' : `
          <div style="display:flex;gap:6px;">
            <button class="icon-btn" onclick='openEditSaleModal(${JSON.stringify(s).replace(/'/g, "&apos;")})' aria-label="Edit">${adminIcon('edit', 14)}</button>
            <button class="icon-btn danger" onclick="deleteSale(${s.id})" aria-label="Delete">${adminIcon('trash', 14)}</button>
          </div>`}
        </td>
      </tr>
    `;
    }).join('');
}

async function deleteSale(id) {
    if (!confirm('Delete this sale? This cannot be undone, and stock will be restored for product sales.')) return;
    const res = await adminApi('/sales.php?id=' + id, { method: 'DELETE' });
    if (res.success) { adminToast('Sale deleted.', 'success'); loadItemsForSearch(); loadSales(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}

// ---------------------------------------------------------
// Edit sale
// ---------------------------------------------------------
function updateEditItemTypeVisibility() {
    const isProduct = document.querySelector('input[name="e_itemType"]:checked').value === 'product';
    document.getElementById('e_productPickWrap').style.display = isProduct ? '' : 'none';
    document.getElementById('e_servicePickWrap').style.display = isProduct ? 'none' : '';
}
function updateEditWorkflowVisibility() {
    const isCash = document.querySelector('input[name="e_saleType"]:checked').value === 'cash';
    document.getElementById('e_cashPaymentSection').style.display = isCash ? '' : 'none';
    document.getElementById('e_creditSection').style.display = isCash ? 'none' : '';
    if (isCash) {
        const channel = document.querySelector('input[name="e_paymentChannel"]:checked').value;
        document.getElementById('e_onlineMethodSection').style.display = channel === 'online' ? '' : 'none';
    }
}

function openEditSaleModal(sale) {
    document.getElementById('editSaleAlert').innerHTML = '';
    document.getElementById('editSaleForm').reset();
    document.getElementById('e_id').value = sale.id;

    const isProduct = sale.item_type !== 'service';
    document.querySelector(`input[name="e_itemType"][value="${sale.item_type}"]`).checked = true;
    updateEditItemTypeVisibility();

    if (isProduct) {
        document.getElementById('e_productSearch').value = sale.product_name;
        document.getElementById('e_productSearch').dataset.selectedId = sale.product_id || '';
        editSelectedProduct = { id: sale.product_id, name: sale.product_name };
    } else {
        document.getElementById('e_serviceSearch').value = sale.product_name;
        document.getElementById('e_serviceSearch').dataset.selectedId = sale.service_id || '';
        editSelectedService = { id: sale.service_id, name: sale.product_name };
    }
    document.getElementById('e_qty').value = sale.quantity;
    document.getElementById('e_serviceQty').value = sale.quantity;
    document.getElementById('e_unitPrice').value = sale.unit_price;
    document.getElementById('e_date').value = sale.sale_date;
    document.getElementById('e_notes').value = sale.notes || '';

    document.querySelector(`input[name="e_saleType"][value="${sale.sale_type}"]`).checked = true;
    if (sale.sale_type === 'cash') {
        const isOnline = sale.payment_method && sale.payment_method !== 'cash_in_hand';
        document.querySelector(`input[name="e_paymentChannel"][value="${isOnline ? 'online' : 'cash_in_hand'}"]`).checked = true;
        if (isOnline) {
            const mm = document.querySelector(`input[name="e_mobileMoneyMethod"][value="${sale.payment_method}"]`);
            if (mm) mm.checked = true;
        }
    } else {
        document.getElementById('e_custName').value = sale.customer_name || '';
        document.getElementById('e_custPhone').value = sale.customer_phone || '';
        document.getElementById('e_custDeadline').value = sale.debt_deadline || '';
        const hasPayments = sale.debt_amount_paid !== undefined && Number(sale.debt_amount_paid) > 0;
        if (hasPayments) {
            document.getElementById('editSaleAlert').innerHTML = `<div class="alert alert-info">This credit sale already has payments recorded, so it can't be edited here. Manage it from the Debts / Credit page instead.</div>`;
            document.getElementById('editSaleSaveBtn').disabled = true;
        } else {
            document.getElementById('editSaleSaveBtn').disabled = false;
        }
    }
    updateEditWorkflowVisibility();

    document.getElementById('editSaleBackdrop').classList.add('open');
}
function closeEditSaleModal() {
    document.getElementById('editSaleBackdrop').classList.remove('open');
    document.getElementById('editSaleSaveBtn').disabled = false;
}

async function submitEditSale(e) {
    e.preventDefault();
    const alertBox = document.getElementById('editSaleAlert');
    alertBox.innerHTML = '';

    const isProduct = document.querySelector('input[name="e_itemType"]:checked').value === 'product';
    const unitPrice = Number(document.getElementById('e_unitPrice').value);
    const saleType = document.querySelector('input[name="e_saleType"]:checked').value;

    const payload = {
        id: Number(document.getElementById('e_id').value),
        sale_date: document.getElementById('e_date').value,
        notes: document.getElementById('e_notes').value.trim(),
        sale_type: saleType,
        unit_price: unitPrice,
    };

    let quantity;
    if (isProduct) {
        const selectedId = document.getElementById('e_productSearch').dataset.selectedId;
        if (!selectedId) {
            alertBox.innerHTML = `<div class="alert alert-error">Please choose a product from the suggestions list.</div>`;
            return;
        }
        quantity = Number(document.getElementById('e_qty').value);
        payload.item_type = 'product';
        payload.product_id = Number(selectedId);
    } else {
        const selectedId = document.getElementById('e_serviceSearch').dataset.selectedId;
        if (!selectedId) {
            alertBox.innerHTML = `<div class="alert alert-error">Please choose a service from the suggestions list.</div>`;
            return;
        }
        quantity = Number(document.getElementById('e_serviceQty').value);
        payload.item_type = 'service';
        payload.service_id = Number(selectedId);
    }
    if (!quantity || quantity <= 0) {
        alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid quantity.</div>`;
        return;
    }
    payload.quantity = quantity;

    if (saleType === 'cash') {
        const channel = document.querySelector('input[name="e_paymentChannel"]:checked').value;
        payload.payment_method = channel === 'online' ? document.querySelector('input[name="e_mobileMoneyMethod"]:checked').value : 'cash_in_hand';
    } else {
        const custName = document.getElementById('e_custName').value.trim();
        const custPhone = document.getElementById('e_custPhone').value.trim();
        const custDeadline = document.getElementById('e_custDeadline').value;
        if (!custName || !custPhone || !custDeadline) {
            alertBox.innerHTML = `<div class="alert alert-error">Please fill in customer name, phone number, and payment deadline.</div>`;
            return;
        }
        payload.customer_name = custName;
        payload.customer_phone = custPhone;
        payload.payment_deadline = custDeadline;
        payload.credit_notes = document.getElementById('e_custNotes').value.trim();
    }

    if (!confirm('Save these changes to the sale? Stock and any linked debt will be recalculated automatically.')) return;

    const btn = document.getElementById('editSaleSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    const res = await adminApi('/sales.php', { method: 'PUT', body: payload });

    btn.disabled = false;
    btn.textContent = 'Save Changes';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    adminToast('Sale updated.', 'success');
    closeEditSaleModal();
    loadItemsForSearch();
    loadSales();
}
