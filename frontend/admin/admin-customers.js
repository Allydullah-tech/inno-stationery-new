/* Customers page: standing, credit history, restrictions and change owed. */
let me = null, worker = false;
const money = adminFormatMoney, esc = adminEscapeHtml;
const RISK = { good: ['good', 'Good standing'], owing: ['info', 'Owing'], late: ['warn', 'Paying late'], restricted: ['bad', 'Restricted'] };

(async () => {
    me = await guardAdminPage();
    if (!me) return;
    worker = adminIsWorker(me);
    renderAdminShell('customers', 'Customers', me);
    document.querySelectorAll('.v3-tab').forEach(t => t.addEventListener('click', () => {
        document.querySelectorAll('.v3-tab').forEach(x => x.classList.toggle('active', x === t));
        document.querySelectorAll('.v3-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + t.dataset.tab));
    }));
    document.getElementById('custSearch').addEventListener('input', adminDebounce(loadCustomers, 300));
    document.getElementById('custRisk').addEventListener('change', loadCustomers);
    loadCustomers(); loadChange();
})();

async function loadCustomers() {
    const q = document.getElementById('custSearch').value.trim(), risk = document.getElementById('custRisk').value;
    const res = await adminApi('/customers.php?q=' + encodeURIComponent(q) + '&risk=' + risk);
    const tb = document.getElementById('custRows');
    if (!res.success) { tb.innerHTML = `<tr><td colspan="8">${esc(res.message || 'Could not load')}</td></tr>`; return; }
    const s = res.summary;
    document.getElementById('custStats').innerHTML = `
      <div class="v3-stat orange"><div class="ico">${adminIcon('users', 18)}</div><div class="lbl">Customers</div><div class="val" id="cs1">0</div></div>
      <div class="v3-stat gold"><div class="ico">${adminIcon('credit-card', 18)}</div><div class="lbl">Owed to us</div><div class="val" id="cs2">0</div></div>
      <div class="v3-stat red"><div class="ico">${adminIcon('alert', 18)}</div><div class="lbl">Overdue</div><div class="val" id="cs3">0</div></div>
      <div class="v3-stat ${s.restricted ? 'red' : 'green'}"><div class="ico">${adminIcon('shield', 18)}</div><div class="lbl">Restricted</div><div class="val" id="cs4">0</div></div>`;
    adminCountUp(document.getElementById('cs1'), s.customers, { money: false });
    adminCountUp(document.getElementById('cs2'), s.owing); adminCountUp(document.getElementById('cs3'), s.overdue);
    adminCountUp(document.getElementById('cs4'), s.restricted, { money: false });
    if (!res.customers.length) { tb.innerHTML = `<tr><td colspan="8" class="v3-empty">${adminIcon('users', 42)}<div>No customers yet — they appear automatically when you make a credit sale.</div></td></tr>`; return; }
    tb.innerHTML = res.customers.map(c => {
        const [cls, label] = RISK[c.risk];
        const pct = c.total_credit > 0 ? Math.min(100, Math.round(c.total_paid / c.total_credit * 100)) : 0;
        return `<tr>
          <td><div class="v3-person"><div class="v3-avatar">${esc(c.name.charAt(0).toUpperCase())}</div><div><b>${esc(c.name)}</b><span>${esc(c.phone)}</span></div></div></td>
          <td><span class="v3-chip ${cls} ${c.risk === 'restricted' ? 'pulse' : ''}">${label}</span></td>
          <td class="v3-money">${money(c.total_credit)}<div class="v3-meter ${c.overdue_count ? 'late' : ''}" title="${pct}% repaid"><i style="width:${pct}%"></i></div></td>
          <td class="v3-money">${money(c.total_paid)}</td>
          <td class="v3-money ${c.total_balance > 0 ? 'v3-neg' : ''}"><b>${money(c.total_balance)}</b></td>
          <td>${c.overdue_count ? `<span class="v3-chip bad">${c.overdue_count} · ${money(c.overdue_amount)}</span>` : '—'}</td>
          <td>${esc(c.last_credit_date || '—')}</td>
          <td><button class="btn btn-outline btn-sm" onclick="openCustomer(${c.id})">Open</button></td></tr>`;
    }).join('');
}

async function openCustomer(id) {
    const res = await adminApi('/customers.php?id=' + id);
    if (!res.success) return adminToast(res.message, 'error');
    const c = res.customer, [cls, label] = RISK[c.risk];
    document.getElementById('custModalTitle').textContent = c.name;
    document.getElementById('custModalBody').innerHTML = `
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
        <div class="v3-avatar" style="width:52px;height:52px;font-size:20px;">${esc(c.name.charAt(0).toUpperCase())}</div>
        <div><div style="font:700 16px var(--font-display);">${esc(c.name)}</div><div class="v3-muted">${esc(c.phone)}</div></div>
        <span class="v3-chip ${cls}" style="margin-left:auto;">${label}</span>
      </div>
      ${c.status === 'restricted' ? `<div class="v3-banner bad">${adminIcon('alert', 18)}<div>${esc(c.restriction_reason || 'Restricted from credit.')}</div></div>` : ''}
      <div class="v3-stats" style="grid-template-columns:repeat(3,1fr);">
        <div class="v3-stat orange"><div class="lbl">Credit taken</div><div class="val" style="font-size:17px;">${money(c.total_credit)}</div></div>
        <div class="v3-stat green"><div class="lbl">Repaid</div><div class="val" style="font-size:17px;">${money(c.total_paid)}</div></div>
        <div class="v3-stat red"><div class="lbl">Still owes</div><div class="val" style="font-size:17px;">${money(c.total_balance)}</div></div>
      </div>
      <h4 style="margin:6px 0 8px;font-size:13px;">Credit history</h4>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Due</th><th>Status</th></tr></thead><tbody>
      ${res.debts.length ? res.debts.map(d => `<tr><td>${esc(d.sale_date)}</td><td>${d.receipt_no ? `<a href="receipt.html?no=${encodeURIComponent(d.receipt_no)}" style="color:var(--navy-deep);font-weight:600;">${esc(d.receipt_no)}</a>` : '—'}</td>
        <td class="v3-money">${money(d.original_amount)}</td><td class="v3-money">${money(d.amount_paid)}</td><td class="v3-money">${money(d.balance)}</td><td>${esc(d.payment_deadline)}</td><td>${debtStatusPill(d.status)}</td></tr>`).join('') : '<tr><td colspan="7" class="v3-muted">No credit history.</td></tr>'}
      </tbody></table></div>
      ${res.change.length ? `<div class="v3-banner warn" style="margin-top:14px;">${adminIcon('wallet', 18)}<div>We still owe this customer <b>${money(res.change.reduce((a, h) => a + h.owed, 0))}</b> in change. Hand it back from the “Change owed” tab.</div></div>` : ''}
      ${worker ? '' : `<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">
          <button class="btn btn-outline btn-sm" onclick="renameCustomer(${c.id}, '${esc(c.name).replace(/'/g, '&#39;')}')">${adminIcon('edit', 14)} Rename</button>
          ${c.status === 'restricted'
            ? `<button class="btn btn-green btn-sm" onclick="setRestriction(${c.id}, 'lift')">${adminIcon('check', 14)} Lift restriction</button>`
            : `<button class="btn btn-danger btn-sm" onclick="setRestriction(${c.id}, 'restrict')">${adminIcon('shield', 14)} Block credit</button>`}
        </div>`}`;
    adminOpenModal('custModal');
}

async function setRestriction(id, action) {
    const reason = prompt(action === 'lift' ? 'Why are you lifting the restriction? (optional)' : 'Why are you blocking credit for this customer?');
    if (reason === null) return;
    const res = await adminApi('/customers.php?action=' + action, { method: 'POST', body: { id, reason } });
    adminToast(res.message, res.success ? 'success' : 'error');
    if (res.success) { adminCloseModal('custModal'); loadCustomers(); }
}
async function renameCustomer(id, current) {
    const name = prompt('Customer name:', current.replace(/&#39;/g, "'"));
    if (!name) return;
    const res = await adminApi('/customers.php', { method: 'PUT', body: { id, name } });
    adminToast(res.message, res.success ? 'success' : 'error');
    if (res.success) { adminCloseModal('custModal'); loadCustomers(); }
}

async function loadChange() {
    const res = await adminApi('/customers.php?view=change');
    const tb = document.getElementById('changeRows');
    if (!res.success) return;
    document.getElementById('changeCount').textContent = res.holds.length;
    tb.innerHTML = res.holds.length ? res.holds.map(h => `
      <tr><td>${esc(h.hold_date)}</td><td><b>${esc(h.customer_name || '—')}</b><div class="v3-muted">${esc(h.customer_phone || '')}</div></td>
      <td>${h.receipt_no ? `<a href="receipt.html?no=${encodeURIComponent(h.receipt_no)}" style="color:var(--navy-deep);font-weight:600;">${esc(h.receipt_no)}</a>` : '—'}</td>
      <td class="v3-money">${money(h.amount)}</td><td class="v3-money v3-neg"><b>${money(h.owed)}</b></td>
      <td><button class="btn btn-green btn-sm" onclick="collectChange(${h.id}, ${h.owed})">Hand back</button></td></tr>`).join('')
        : `<tr><td colspan="6" class="v3-empty">${adminIcon('check', 42)}<div>No change is owed to anyone.</div></td></tr>`;
}
async function collectChange(id, owed) {
    const v = prompt('How much change are you handing back now?', owed);
    if (v === null) return;
    const res = await adminApi('/customers.php?action=collect', { method: 'POST', body: { hold_id: id, amount: Number(v) } });
    adminToast(res.message, res.success ? 'success' : 'error');
    if (res.success) loadChange();
}
