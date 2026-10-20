/* Sales page: point-of-sale cart (several items per receipt) + history. */
let me = null, worker = false;
let products = [], services = [], materials = [];
let kind = 'product', saleType = 'cash', payMethod = 'cash_in_hand';
let cart = [];                 // {key,type,id,name,qty,price,list,min,stock,low,materials:[]}
let editing = null;            // {receipt_no, held:{product_id:qty}} while an admin edits a receipt
let custRestricted = false;
const money = adminFormatMoney, esc = adminEscapeHtml;

(async () => {
    me = await guardAdminPage();
    if (!me) return;
    worker = adminIsWorker(me);
    renderAdminShell('sales', 'Sales', me);

    const d = document.getElementById('saleDate');
    d.value = adminToday(); d.max = adminToday(); if (worker) d.disabled = true;
    document.getElementById('deadline').value = adminToday(7);
    document.getElementById('hFrom').value = adminToday(-6); document.getElementById('hTo').value = adminToday();

    document.querySelectorAll('.v3-tab').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));
    document.querySelectorAll('#kindToggle button').forEach(b => b.addEventListener('click', () => { kind = b.dataset.kind; document.querySelectorAll('#kindToggle button').forEach(x => x.classList.toggle('active', x === b)); renderPick(); }));
    document.querySelectorAll('#saleTypeToggle button').forEach(b => b.addEventListener('click', () => setSaleType(b.dataset.type)));
    document.querySelectorAll('#payButtons button').forEach(b => b.addEventListener('click', () => { payMethod = b.dataset.m; document.querySelectorAll('#payButtons button').forEach(x => x.classList.toggle('active', x === b)); updatePay(); }));
    document.getElementById('pickSearch').addEventListener('input', renderPick);
    document.getElementById('clearCart').addEventListener('click', () => { cart = []; renderCart(); });
    document.getElementById('received').addEventListener('input', updatePay);
    document.getElementById('keepChange').addEventListener('change', updatePay);
    document.getElementById('completeBtn').addEventListener('click', submitSale);
    document.getElementById('cancelEditBtn').addEventListener('click', cancelEdit);
    document.getElementById('custPhone').addEventListener('blur', lookupCustomer);
    document.getElementById('hGo').addEventListener('click', loadHistory);
    document.getElementById('hSearch').addEventListener('input', adminDebounce(loadHistory, 350));
    document.getElementById('doneModal').addEventListener('click', e => { if (e.target.id === 'doneModal') adminCloseModal('doneModal'); });

    await loadCatalog();
    renderCart();
    const editNo = new URLSearchParams(location.search).get('edit');
    if (editNo && !worker) startEdit(editNo);
})();

function switchTab(name) {
    document.querySelectorAll('.v3-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.v3-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
    if (name === 'history') loadHistory();
}

async function loadCatalog() {
    const [p, s, m] = await Promise.all([adminApi('/products.php'), adminApi('/services.php'), adminApi('/service_purchases.php?view=items')]);
    products = (p.products || []).filter(x => x.status === 'active');
    services = (s.services || []).filter(x => x.status === 'active');
    materials = m.items || [];
    renderPick();
}

/* ---------------- picker ---------------- */
function renderPick() {
    const q = document.getElementById('pickSearch').value.trim().toLowerCase();
    const list = (kind === 'product' ? products : services).filter(x => !q || x.name.toLowerCase().includes(q));
    const grid = document.getElementById('pickGrid');
    if (!list.length) { grid.innerHTML = `<div class="v3-empty" style="grid-column:1/-1;">${adminIcon('search', 42)}<div>${q ? 'Nothing matches “' + esc(q) + '”.' : 'No ' + kind + 's yet — add some first.'}</div></div>`; return; }
    grid.innerHTML = list.map(x => {
        const held = editing && kind === 'product' ? (editing.held[x.id] || 0) : 0;
        const stock = kind === 'product' ? x.stock + held : null;
        const out = kind === 'product' && stock <= 0;
        return `<button type="button" class="v3-tile ${kind === 'product' && x.stock <= x.low_stock_threshold ? 'low' : ''}" ${out ? 'disabled' : ''} data-id="${x.id}">
          <div class="n">${esc(x.name)}</div><div class="p">${money(kind === 'product' ? x.price : x.selling_price)}</div>
          <div class="s">${kind === 'product' ? (out ? 'Out of stock' : stock + ' in stock') : 'Service'}</div></button>`;
    }).join('');
    grid.querySelectorAll('.v3-tile').forEach(t => t.addEventListener('click', () => addToCart(kind, Number(t.dataset.id))));
}

function addToCart(type, id) {
    const src = (type === 'product' ? products : services).find(x => x.id === id);
    if (!src) return;
    const existing = cart.find(l => l.type === type && l.id === id);
    const held = editing && type === 'product' ? (editing.held[id] || 0) : 0;
    if (existing) {
        if (type === 'product' && existing.qty + 1 > src.stock + held) { adminToast('Only ' + (src.stock + held) + ' in stock.', 'error'); return; }
        existing.qty++;
    } else {
        cart.push({ key: type + id, type, id, name: src.name, qty: 1, price: type === 'product' ? src.price : src.selling_price, list: type === 'product' ? src.price : src.selling_price,
            min: src.minimum_price, cost: type === 'product' ? src.buy_price : 0, stock: type === 'product' ? src.stock + held : null, materials: [] });
    }
    renderCart();
}

/* ---------------- cart ---------------- */
function lineTotal(l) { return (Number(l.qty) || 0) * (Number(l.price) || 0); }
function cartTotal() { return cart.reduce((a, l) => a + lineTotal(l), 0); }

function renderCart() {
    const box = document.getElementById('cartLines');
    document.getElementById('cartCount').textContent = cart.length;
    if (!cart.length) box.innerHTML = `<div class="v3-empty" style="padding:22px 8px;">${adminIcon('cart', 42)}<div>Tap an item on the left to add it.</div></div>`;
    else box.innerHTML = cart.map((l, i) => `
      <div class="v3-line" data-i="${i}">
        <div class="top"><div class="nm">${esc(l.name)}<small>${l.min != null ? 'min ' + money(l.min) + ' · ' : ''}list ${money(l.list)}</small></div>
          <button class="icon-btn danger" data-act="rm" type="button">${adminIcon('trash', 14)}</button></div>
        <div class="ctl"><label class="v3-muted">Qty</label><input type="number" min="1" value="${l.qty}" data-f="qty">
          <label class="v3-muted">Price</label><input type="number" min="0" value="${l.price}" data-f="price" style="width:96px;">
          <span class="amt">${money(lineTotal(l))}</span></div>
        ${l.type === 'service' ? `<div class="mats">${l.materials.map((m, j) => `
            <div class="m" data-j="${j}"><select data-f="mat">${materials.map(x => `<option value="${x.id}" ${x.id === m.service_item_id ? 'selected' : ''}>${esc(x.label)} (${x.stock_quantity} left)</option>`).join('')}</select>
              <input type="number" min="1" value="${m.quantity}" data-f="matq"><button class="icon-btn" data-act="rmmat" type="button">${adminIcon('x', 12)}</button></div>`).join('')}
          ${materials.length ? `<button class="btn btn-ghost btn-sm" data-act="addmat" type="button" style="justify-self:start;">+ Materials used</button>` : ''}</div>` : ''}
      </div>`).join('');
    box.querySelectorAll('.v3-line').forEach(el => {
        const l = cart[Number(el.dataset.i)];
        el.querySelectorAll('[data-f]').forEach(inp => inp.addEventListener('input', () => {
            const f = inp.dataset.f;
            if (f === 'qty') l.qty = Math.max(1, parseInt(inp.value || '1', 10) || 1);
            if (f === 'price') l.price = Math.max(0, Number(inp.value) || 0);
            if (f === 'mat') l.materials[Number(inp.closest('.m').dataset.j)].service_item_id = Number(inp.value);
            if (f === 'matq') l.materials[Number(inp.closest('.m').dataset.j)].quantity = Math.max(1, parseInt(inp.value || '1', 10) || 1);
            if (f === 'qty' || f === 'price') { el.querySelector('.amt').textContent = money(lineTotal(l)); updateTotals(); }
        }));
        el.querySelectorAll('[data-act]').forEach(btn => btn.addEventListener('click', () => {
            const a = btn.dataset.act;
            if (a === 'rm') cart.splice(Number(el.dataset.i), 1);
            if (a === 'addmat') l.materials.push({ service_item_id: materials[0].id, quantity: 1 });
            if (a === 'rmmat') l.materials.splice(Number(btn.closest('.m').dataset.j), 1);
            renderCart();
        }));
    });
    updateTotals();
}

function updateTotals() { document.getElementById('cartTotal').textContent = money(cartTotal()); updatePay(); }

function setSaleType(t) {
    saleType = t;
    document.querySelectorAll('#saleTypeToggle button').forEach(b => b.classList.toggle('active', b.dataset.type === t));
    document.getElementById('cashBox').classList.toggle('v3-hidden', t !== 'cash');
    document.getElementById('creditBox').classList.toggle('v3-hidden', t !== 'credit');
    document.getElementById('custReq').textContent = t === 'credit' ? '(required)' : '(optional)';
    updatePay();
}

function updatePay() {
    const isCashHand = saleType === 'cash' && payMethod === 'cash_in_hand';
    document.getElementById('changeBox').classList.toggle('v3-hidden', !isCashHand);
    const recv = Number(document.getElementById('received').value) || 0, total = cartTotal();
    const change = isCashHand && recv > total ? recv - total : 0;
    document.getElementById('changeDue').textContent = money(change);
    document.getElementById('keepChangeWrap').classList.toggle('v3-hidden', change <= 0);
    if (change <= 0) document.getElementById('keepChange').checked = false;
    const need = (saleType === 'credit') || document.getElementById('keepChange').checked;
    document.getElementById('custReq').textContent = need ? '(required)' : '(optional)';
}

/* ---------------- customer lookup ---------------- */
async function lookupCustomer() {
    const phone = document.getElementById('custPhone').value.trim(), info = document.getElementById('custInfo');
    custRestricted = false; info.innerHTML = '';
    if (phone.replace(/\D/g, '').length < 7) return;
    const res = await adminApi('/customers.php?q=' + encodeURIComponent(phone.replace(/\D/g, '').slice(-9)));
    const c = (res.customers || [])[0];
    if (!c) return;
    const nameEl = document.getElementById('custName');
    if (!nameEl.value.trim()) nameEl.value = c.name;
    if (c.status === 'restricted') { custRestricted = true; info.innerHTML = `<div class="v3-banner bad">${adminIcon('alert', 18)}<div><b>${esc(c.name)}</b> is blocked from credit. ${esc(c.restriction_reason || '')}</div></div>`; }
    else if (c.total_balance > 0) info.innerHTML = `<div class="v3-banner warn">${adminIcon('credit-card', 18)}<div><b>${esc(c.name)}</b> already owes <b>${money(c.total_balance)}</b>${c.overdue_count ? ` (${c.overdue_count} overdue)` : ''}.</div></div>`;
    else info.innerHTML = `<div class="v3-banner ok">${adminIcon('check', 18)}<div><b>${esc(c.name)}</b> — good standing.</div></div>`;
}

/* ---------------- submit ---------------- */
function buildPayload() {
    const recv = Number(document.getElementById('received').value) || 0, total = cartTotal();
    const keep = document.getElementById('keepChange').checked && saleType === 'cash' && payMethod === 'cash_in_hand' ? Math.max(0, recv - total) : 0;
    const p = {
        items: cart.map(l => ({ item_type: l.type, product_id: l.type === 'product' ? l.id : null, service_id: l.type === 'service' ? l.id : null, quantity: l.qty, unit_price: l.price, materials: l.materials })),
        sale_type: saleType, payment_method: saleType === 'cash' ? payMethod : null,
        customer_name: document.getElementById('custName').value.trim(), customer_phone: document.getElementById('custPhone').value.trim(),
        sale_date: document.getElementById('saleDate').value, notes: document.getElementById('saleNotes').value.trim(), change_left: keep,
    };
    if (saleType === 'credit') { p.payment_deadline = document.getElementById('deadline').value; p.credit_notes = document.getElementById('creditNotes').value.trim(); }
    if (editing) p.receipt_no = editing.receipt_no;
    return p;
}

async function submitSale() {
    const box = document.getElementById('saleAlert'); box.innerHTML = '';
    if (!cart.length) { box.innerHTML = '<div class="alert alert-error">Add at least one item.</div>'; return; }
    if (saleType === 'credit' && custRestricted) { box.innerHTML = '<div class="alert alert-error">This customer is blocked from credit.</div>'; return; }
    const btn = document.getElementById('completeBtn'); btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving…';
    const res = await adminApi('/sales.php', { method: editing ? 'PUT' : 'POST', body: buildPayload() });
    btn.disabled = false; btn.textContent = editing ? 'Save changes' : 'Complete sale';
    if (!res.success) { box.innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; return; }
    const wasEdit = !!editing;
    document.getElementById('doneTitle').textContent = wasEdit ? 'Sale updated' : (saleType === 'credit' ? 'Credit sale recorded' : 'Sale recorded');
    document.getElementById('doneSub').textContent = (res.receipt_no ? 'Receipt ' + res.receipt_no : '') + (res.total ? ' · ' + money(res.total) : '');
    document.getElementById('doneReceipt').href = 'receipt.html?no=' + encodeURIComponent(res.receipt_no);
    adminOpenModal('doneModal');
    resetForm(); await loadCatalog(); if (document.getElementById('panel-history').classList.contains('active')) loadHistory();
}

function resetForm() {
    cart = []; editing = null; custRestricted = false;
    ['received', 'custName', 'custPhone', 'saleNotes', 'creditNotes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('keepChange').checked = false; document.getElementById('custInfo').innerHTML = '';
    document.getElementById('saleDate').value = adminToday(); document.getElementById('deadline').value = adminToday(7);
    document.getElementById('editBanner').innerHTML = ''; document.getElementById('cancelEditBtn').classList.add('v3-hidden');
    document.getElementById('completeBtn').textContent = 'Complete sale';
    setSaleType('cash'); payMethod = 'cash_in_hand';
    document.querySelectorAll('#payButtons button').forEach(x => x.classList.toggle('active', x.dataset.m === 'cash_in_hand'));
    renderCart(); renderPick();
}

/* ---------------- edit a saved receipt (admins) ---------------- */
async function startEdit(receiptNo) {
    const res = await adminApi('/sales.php?receipt=' + encodeURIComponent(receiptNo));
    if (!res.success) { adminToast(res.message, 'error'); return; }
    const r = res.receipt;
    resetForm();
    editing = { receipt_no: r.receipt_no, held: {} };
    r.lines.forEach(l => { if (l.item_type === 'product' && l.product_id) editing.held[l.product_id] = (editing.held[l.product_id] || 0) + l.quantity; });
    await loadCatalog();
    r.lines.forEach(l => {
        const type = l.item_type, id = type === 'product' ? l.product_id : l.service_id;
        const src = (type === 'product' ? products : services).find(x => x.id === id);
        const held = type === 'product' ? (editing.held[id] || 0) : 0;
        cart.push({ key: type + id, type, id, name: l.product_name, qty: l.quantity, price: l.unit_price, list: l.list_price ?? l.unit_price, min: src ? src.minimum_price : null,
            cost: l.unit_cost, stock: src && type === 'product' ? src.stock + held : null, materials: [] });
    });
    setSaleType(r.sale_type);
    if (r.sale_type === 'cash') { payMethod = r.payment_method; document.querySelectorAll('#payButtons button').forEach(x => x.classList.toggle('active', x.dataset.m === payMethod)); }
    else { document.getElementById('deadline').value = r.debt_deadline || adminToday(7); }
    document.getElementById('custName').value = r.customer_name || ''; document.getElementById('custPhone').value = r.customer_phone || '';
    document.getElementById('saleDate').value = r.sale_date; document.getElementById('saleNotes').value = r.notes || '';
    document.getElementById('editBanner').innerHTML = `<div class="v3-banner warn">${adminIcon('edit', 18)}<div>Editing receipt <b>${esc(r.receipt_no)}</b>. Saving replaces its items and payment details; stock is corrected automatically. Re-add any service materials that were used.</div></div>`;
    document.getElementById('cancelEditBtn').classList.remove('v3-hidden');
    document.getElementById('completeBtn').textContent = 'Save changes';
    renderCart(); renderPick(); switchTab('pos'); window.scrollTo({ top: 0, behavior: 'smooth' });
}
function cancelEdit() { resetForm(); loadCatalog(); }

/* ---------------- history ---------------- */
async function loadHistory() {
    const p = new URLSearchParams({ from: document.getElementById('hFrom').value, to: document.getElementById('hTo').value, search: document.getElementById('hSearch').value.trim(), sale_type: document.getElementById('hType').value, limit: 5000 });
    const res = await adminApi('/sales.php?' + p.toString());
    const tb = document.getElementById('histRows');
    if (!res.success) { tb.innerHTML = `<tr><td colspan="8">${esc(res.message || 'Could not load')}</td></tr>`; return; }
    const groups = new Map();
    res.sales.forEach(s => { const k = s.receipt_no || ('legacy-' + s.id); if (!groups.has(k)) groups.set(k, { key: k, receipt: s.receipt_no, rows: [] }); groups.get(k).rows.push(s); });
    let total = 0, cash = 0, mobile = 0, credit = 0, profit = 0;
    const list = [...groups.values()];
    list.forEach(g => g.rows.forEach(s => {
        total += s.total_amount; if (s.sale_type === 'credit') credit += s.total_amount; else if (s.payment_method === 'cash_in_hand') cash += s.total_amount; else mobile += s.total_amount;
        if (s.profit != null) profit += s.profit;
    }));
    document.getElementById('histStats').innerHTML = `
      <div class="v3-stat orange"><div class="ico">${adminIcon('receipt', 18)}</div><div class="lbl">Receipts</div><div class="val">${list.length}</div></div>
      <div class="v3-stat green"><div class="ico">${adminIcon('cart', 18)}</div><div class="lbl">Total sales</div><div class="val">${money(total)}</div></div>
      <div class="v3-stat gold"><div class="ico">${adminIcon('wallet', 18)}</div><div class="lbl">Cash / mobile</div><div class="val" style="font-size:16px;">${money(cash)} / ${money(mobile)}</div></div>
      <div class="v3-stat red"><div class="ico">${adminIcon('credit-card', 18)}</div><div class="lbl">On credit</div><div class="val">${money(credit)}</div></div>
      ${worker ? '' : `<div class="v3-stat ${profit < 0 ? 'red' : 'green'}"><div class="ico">${adminIcon('trending-up', 18)}</div><div class="lbl">Gross profit</div><div class="val">${money(profit)}</div></div>`}`;
    if (!list.length) { tb.innerHTML = `<tr><td colspan="8" class="v3-empty">${adminIcon('receipt', 42)}<div>No sales found for these filters.</div></td></tr>`; return; }
    tb.innerHTML = list.map(g => {
        const f = g.rows[0], sum = g.rows.reduce((a, s) => a + s.total_amount, 0);
        const items = g.rows.map(s => `<div>${esc(s.product_name)} ×${s.quantity}</div>`).join('');
        const pay = f.sale_type === 'credit' ? `<span class="v3-chip bad">Credit</span>${f.debt_status ? '<div style="margin-top:3px;">' + debtStatusPill(f.debt_status) + '</div>' : ''}` : `<span class="v3-chip good">${esc(paymentMethodLabel(f.payment_method))}</span>`;
        const act = [];
        if (g.receipt) act.push(`<a class="icon-btn" title="Receipt" href="receipt.html?no=${encodeURIComponent(g.receipt)}">${adminIcon('receipt', 14)}</a>`);
        if (!worker) { act.push(`<button class="icon-btn" title="Edit" onclick="startEdit('${g.receipt || ''}')" ${g.receipt ? '' : 'disabled'}>${adminIcon('edit', 14)}</button>`);
            act.push(`<button class="icon-btn danger" title="Delete" onclick="deleteSale('${g.receipt || ''}', ${f.id})">${adminIcon('trash', 14)}</button>`); }
        return `<tr><td>${esc(f.sale_date)}</td><td class="v3-money">${esc(g.receipt || '#' + f.id)}</td><td style="max-width:280px;">${items}</td>
          <td>${f.customer_name ? `<b>${esc(f.customer_name)}</b><div class="v3-muted">${esc(f.customer_phone || '')}</div>` : '—'}</td><td>${pay}</td>
          <td class="v3-money"><b>${money(sum)}</b></td><td>${esc(f.recorded_by_name || '—')}</td><td style="white-space:nowrap;">${act.join('')}</td></tr>`;
    }).join('');
}

async function deleteSale(receipt, id) {
    if (!confirm('Delete this whole sale? Stock and service materials are put back.')) return;
    const res = await adminApi('/sales.php?' + (receipt ? 'receipt=' + encodeURIComponent(receipt) : 'id=' + id), { method: 'DELETE' });
    adminToast(res.message, res.success ? 'success' : 'error');
    if (res.success) { loadHistory(); loadCatalog(); }
}
