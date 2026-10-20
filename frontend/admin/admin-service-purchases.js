/* Service materials: stock, purchases, write-offs and service profit. */
let me = null, worker = false, stockItems = [];
const money = adminFormatMoney, esc = adminEscapeHtml;
const PAID = { cash: 'Till cash', online: 'Mobile money', other: 'Bank / other' };

(async () => {
    me = await guardAdminPage();
    if (!me) return;
    worker = adminIsWorker(me);
    renderAdminShell('service-purchases', 'Service Materials', me);
    if (worker) document.querySelectorAll('.admin-only').forEach(e => e.remove());
    document.querySelectorAll('.v3-tab').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));
    document.getElementById('spDate').value = adminToday(); document.getElementById('spDate').max = adminToday();
    document.getElementById('hFrom').value = adminToday(-30); document.getElementById('hTo').value = adminToday();
    if (!worker) {
        document.getElementById('addRowBtn').addEventListener('click', () => addBuyRow());
        document.getElementById('buySaveBtn').addEventListener('click', savePurchase);
        document.getElementById('hGo').addEventListener('click', loadHistory);
        document.getElementById('pPeriod').addEventListener('change', loadProfit);
        document.getElementById('editSave').addEventListener('click', saveEdit);
        document.getElementById('wasteSave').addEventListener('click', saveWaste);
        addBuyRow();
    }
    loadStock();
})();

function switchTab(name) {
    document.querySelectorAll('.v3-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.v3-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
    if (name === 'history') loadHistory();
    if (name === 'profit') loadProfit();
    if (name === 'stock') loadStock();
}

async function loadStock() {
    const res = await adminApi('/service_purchases.php?view=stock');
    if (!res.success) return;
    stockItems = res.items;
    document.getElementById('matNames').innerHTML = [...new Set(res.items.map(i => i.name))].map(n => `<option value="${esc(n)}">`).join('');
    const low = res.items.filter(i => i.stock_quantity <= 5).length;
    document.getElementById('spStats').innerHTML = `
      <div class="v3-stat orange"><div class="ico">${adminIcon('layers', 18)}</div><div class="lbl">Materials</div><div class="val" id="sp1">0</div></div>
      <div class="v3-stat ${low ? 'red' : 'green'}"><div class="ico">${adminIcon('alert', 18)}</div><div class="lbl">Running low (≤5)</div><div class="val" id="sp2">0</div></div>
      ${worker ? '' : `<div class="v3-stat gold"><div class="ico">${adminIcon('wallet', 18)}</div><div class="lbl">Stock value</div><div class="val" id="sp3">0</div></div>`}`;
    adminCountUp(document.getElementById('sp1'), res.items.length, { money: false });
    adminCountUp(document.getElementById('sp2'), low, { money: false });
    if (!worker) adminCountUp(document.getElementById('sp3'), res.stock_value || 0);
    const cols = worker ? 4 : 7;
    document.getElementById('stockRows').innerHTML = res.items.length ? res.items.map(i => `
      <tr><td><b>${esc(i.name)}</b>${i.description ? `<div class="v3-muted">${esc(i.description)}</div>` : ''}</td>
      <td class="v3-money">${i.total_purchased} ${esc(i.unit)}</td><td class="v3-money">${i.total_used}</td>
      <td><span class="v3-chip ${i.stock_quantity <= 0 ? 'bad' : i.stock_quantity <= 5 ? 'warn' : 'good'}">${i.stock_quantity} ${esc(i.unit)}</span></td>
      ${worker ? '' : `<td class="v3-money">${money(i.buying_price)}</td><td class="v3-money">${money(i.stock_value)}</td>
      <td><button class="btn btn-outline btn-sm" onclick="openWaste(${i.id})">Write off</button></td>`}</tr>`).join('')
        : `<tr><td colspan="${cols}" class="v3-empty">${adminIcon('layers', 42)}<div>No service materials yet.${worker ? '' : ' Record your first purchase.'}</div></td></tr>`;
}

/* ---- record purchase ---- */
function addBuyRow() {
    const wrap = document.getElementById('buyRows'), d = document.createElement('div');
    d.className = 'v3-line'; d.style.marginBottom = '10px';
    d.innerHTML = `<div class="field-row-3" style="margin:0;">
        <div class="field" style="margin:0;"><label>Material *</label><input type="text" class="b-name" list="matNames" placeholder="e.g. A4 paper" maxlength="150"></div>
        <div class="field" style="margin:0;"><label>Type / size</label><input type="text" class="b-desc" placeholder="e.g. 80gsm" maxlength="190"></div>
        <div class="field" style="margin:0;"><label>Unit</label><input type="text" class="b-unit" value="pcs" maxlength="30"></div></div>
      <div class="ctl"><label class="v3-muted">Qty</label><input type="number" class="b-qty" min="1" placeholder="0">
        <label class="v3-muted">Price each</label><input type="number" class="b-price" min="1" placeholder="0">
        <span class="amt b-amt">0</span><button class="icon-btn danger" title="Remove" type="button">${adminIcon('trash', 14)}</button></div>`;
    d.querySelector('.icon-btn').addEventListener('click', () => { if (wrap.children.length > 1) { d.remove(); calcBuy(); } });
    d.querySelectorAll('input').forEach(i => i.addEventListener('input', calcBuy));
    // fill type / price from an existing material of the same name
    d.querySelector('.b-name').addEventListener('change', e => {
        const m = stockItems.find(i => i.name.toLowerCase() === e.target.value.trim().toLowerCase());
        if (m && !d.querySelector('.b-desc').value) { d.querySelector('.b-desc').value = m.description || ''; d.querySelector('.b-unit').value = m.unit; if (m.buying_price) d.querySelector('.b-price').value = m.buying_price; calcBuy(); }
    });
    wrap.appendChild(d);
}
function calcBuy() {
    let t = 0;
    document.querySelectorAll('#buyRows .v3-line').forEach(r => {
        const a = (Number(r.querySelector('.b-qty').value) || 0) * (Number(r.querySelector('.b-price').value) || 0);
        r.querySelector('.b-amt').textContent = money(a); t += a;
    });
    document.getElementById('buyTotal').textContent = money(t);
}
async function savePurchase() {
    const box = document.getElementById('buyAlert'); box.innerHTML = '';
    const items = [...document.querySelectorAll('#buyRows .v3-line')].map(r => ({
        name: r.querySelector('.b-name').value.trim(), description: r.querySelector('.b-desc').value.trim(), unit: r.querySelector('.b-unit').value.trim() || 'pcs',
        quantity: Number(r.querySelector('.b-qty').value), buying_price: Number(r.querySelector('.b-price').value) })).filter(i => i.name || i.quantity || i.buying_price);
    const btn = document.getElementById('buySaveBtn'); btn.disabled = true;
    const res = await adminApi('/service_purchases.php', { method: 'POST', body: { purchase_date: document.getElementById('spDate').value, paid_from: document.getElementById('spPaid').value, note: document.getElementById('spNote').value.trim(), items } });
    btn.disabled = false;
    if (!res.success) { box.innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; return; }
    adminToast(res.message, 'success');
    document.getElementById('buyRows').innerHTML = ''; addBuyRow(); calcBuy(); document.getElementById('spNote').value = '';
    switchTab('stock');
}

/* ---- history ---- */
async function loadHistory() {
    const q = `?from=${document.getElementById('hFrom').value}&to=${document.getElementById('hTo').value}&search=${encodeURIComponent(document.getElementById('hSearch').value.trim())}`;
    const res = await adminApi('/service_purchases.php' + q);
    const tb = document.getElementById('histRows');
    tb.innerHTML = res.success && res.purchases.length ? res.purchases.map(p => `
      <tr><td>${esc(p.purchase_date)}</td><td><b>${esc(p.item_name)}</b>${p.item_description ? `<div class="v3-muted">${esc(p.item_description)}</div>` : ''}</td>
      <td class="v3-money">${p.quantity} ${esc(p.unit)}</td><td class="v3-money">${money(p.buying_price)}</td><td class="v3-money"><b>${money(p.total_cost)}</b></td>
      <td><span class="v3-chip">${PAID[p.paid_from] || p.paid_from}</span></td><td>${esc(p.recorded_by_name || '—')}</td>
      <td style="white-space:nowrap;"><button class="icon-btn" onclick='openEdit(${JSON.stringify(p).replace(/'/g, "&#39;")})'>${adminIcon('edit', 14)}</button><button class="icon-btn danger" onclick="delPurchase(${p.id})">${adminIcon('trash', 14)}</button></td></tr>`).join('')
        : `<tr><td colspan="8" class="v3-empty">No purchases in this range.</td></tr>`;
}
function openEdit(p) {
    document.getElementById('editId').value = p.id; document.getElementById('editQty').value = p.quantity; document.getElementById('editPrice').value = p.buying_price;
    document.getElementById('editDate').value = p.purchase_date; document.getElementById('editDate').max = adminToday();
    document.getElementById('editPaid').value = p.paid_from; document.getElementById('editNote').value = p.note || '';
    document.getElementById('editAlert').innerHTML = ''; adminOpenModal('editModal');
}
async function saveEdit() {
    const res = await adminApi('/service_purchases.php', { method: 'PUT', body: { id: Number(document.getElementById('editId').value), quantity: Number(document.getElementById('editQty').value), buying_price: Number(document.getElementById('editPrice').value), purchase_date: document.getElementById('editDate').value, paid_from: document.getElementById('editPaid').value, note: document.getElementById('editNote').value.trim() } });
    if (!res.success) { document.getElementById('editAlert').innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; return; }
    adminToast(res.message, 'success'); adminCloseModal('editModal'); loadHistory(); loadStock();
}
async function delPurchase(id) {
    if (!confirm('Delete this purchase? Its quantity is removed from stock.')) return;
    const r = await adminApi('/service_purchases.php?id=' + id, { method: 'DELETE' });
    adminToast(r.message, r.success ? 'success' : 'error'); if (r.success) { loadHistory(); loadStock(); }
}

/* ---- write-off ---- */
function openWaste(id) {
    const i = stockItems.find(x => x.id === id);
    document.getElementById('wasteId').value = id; document.getElementById('wasteLabel').textContent = `${i.label} — ${i.stock_quantity} ${i.unit} in stock`;
    document.getElementById('wasteQty').value = ''; document.getElementById('wasteNote').value = ''; document.getElementById('wasteAlert').innerHTML = ''; adminOpenModal('wasteModal');
}
async function saveWaste() {
    const res = await adminApi('/service_purchases.php?action=waste', { method: 'POST', body: { service_item_id: Number(document.getElementById('wasteId').value), quantity: Number(document.getElementById('wasteQty').value), reason: document.getElementById('wasteReason').value, note: document.getElementById('wasteNote').value.trim() } });
    if (!res.success) { document.getElementById('wasteAlert').innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; return; }
    adminToast(res.message, 'success'); adminCloseModal('wasteModal'); loadStock();
}

/* ---- profit ---- */
async function loadProfit() {
    const r = await adminApi('/service_purchases.php?view=profit&period=' + document.getElementById('pPeriod').value);
    if (!r.success) return;
    document.getElementById('profitStats').innerHTML = `
      <div class="v3-stat orange"><div class="lbl">Service sales</div><div class="val">${money(r.service_sales)}</div></div>
      <div class="v3-stat gold"><div class="lbl">Unpaid on credit</div><div class="val">${money(r.unpaid_credit)}</div></div>
      <div class="v3-stat green"><div class="lbl">Collected</div><div class="val">${money(r.collected)}</div></div>
      <div class="v3-stat red"><div class="lbl">Materials bought</div><div class="val">${money(r.materials_bought)}</div><div class="sub">${money(r.materials_wasted)} written off</div></div>
      <div class="v3-stat ${r.profit < 0 ? 'red' : 'green'}"><div class="lbl">Service profit</div><div class="val">${money(r.profit)}</div><div class="sub">${esc(r.from)} → ${esc(r.to)}</div></div>`;
}
