/* Cash Audit page: count the drawer, compare with the system, bank the cash. */
let admin = null, worker = false;
let preview = null;          // last /cash_audit.php?action=preview response
let editingId = null;        // set while an admin corrects a saved audit
const money = adminFormatMoney, esc = adminEscapeHtml;

(async () => {
    admin = await guardAdminPage();
    if (!admin) return;
    worker = adminIsWorker(admin);
    renderAdminShell('cash-audit', 'Cash Audit', admin);

    const dateEl = document.getElementById('auditDate');
    dateEl.value = adminToday();
    dateEl.max = adminToday();
    if (worker) { dateEl.min = adminToday(); dateEl.disabled = true; }
    if (worker) document.querySelectorAll('#floatType option.admin-only').forEach(o => o.remove());
    document.getElementById('histFrom').value = adminToday(-30);
    document.getElementById('histTo').value = adminToday();

    document.querySelectorAll('.v3-tab').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));
    dateEl.addEventListener('change', () => { cancelEdit(); loadDay(); });
    document.getElementById('saveAuditBtn').addEventListener('click', saveAudit);
    document.getElementById('cancelEditBtn').addEventListener('click', () => { cancelEdit(); loadDay(); });
    document.getElementById('countedOnline').addEventListener('input', recalc);
    document.getElementById('floatForm').addEventListener('submit', saveFloat);
    document.getElementById('depositForm').addEventListener('submit', saveDeposit);
    document.getElementById('histFilter').addEventListener('click', loadHistory);
    loadDay();
})();

function switchTab(name) {
    document.querySelectorAll('.v3-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.v3-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
    if (name === 'history') loadHistory();
    if (name === 'float') loadFloatAndDeposits();
}

const selDate = () => document.getElementById('auditDate').value;

async function loadDay() {
    const res = await adminApi('/cash_audit.php?action=preview&date=' + selDate());
    if (!res.success) { adminToast(res.message || 'Could not load.', 'error'); return; }
    preview = res;
    buildDenoms(res.denoms, res.audit && !editingId ? res.audit.denominations : null);
    renderFigures();
    renderStatus();
    recalc();
    loadFloatAndDeposits();
}

function buildDenoms(denoms, saved) {
    const grid = document.getElementById('denomGrid');
    const qtyOf = d => { const r = (saved || []).find(x => x.denom === d); return r ? r.qty : ''; };
    grid.innerHTML = denoms.map(d => `
      <div class="v3-denom">
        <div class="d">${money(d)}<small>${d >= 1000 ? 'note' : 'coin'}</small></div>
        <input type="number" min="0" inputmode="numeric" data-denom="${d}" placeholder="0" value="${qtyOf(d)}">
        <div class="sub" id="sub-${d}"></div>
      </div>`).join('');
    grid.querySelectorAll('input').forEach(i => i.addEventListener('input', recalc));
}

function counted() {
    let total = 0;
    document.querySelectorAll('#denomGrid input').forEach(i => {
        const q = Math.max(0, parseInt(i.value || '0', 10) || 0), d = Number(i.dataset.denom);
        total += q * d;
        document.getElementById('sub-' + d).textContent = q ? money(q * d) : '';
    });
    return total;
}

function row(sign, label, val, hint) {
    return `<div class="row"><span><span class="sign">${sign}</span>${label}${hint ? `<span class="hint">${hint}</span>` : ''}</span><span class="v3-money">${money(val)}</span></div>`;
}

function renderFigures() {
    const f = preview.figures, fl = f.float;
    document.getElementById('figDate').textContent = preview.date;
    const src = fl.base_source === 'carry_over' ? `carried over from ${fl.carry_over_from}` : fl.base_source === 'typed' ? 'set by an admin' : 'no earlier audit';
    document.getElementById('calcCash').innerHTML =
        `<div style="font:700 12px var(--font-body);letter-spacing:.08em;text-transform:uppercase;color:var(--ink-soft);padding-top:10px;">Cash in the drawer</div>` +
        row('', 'Opening cash', f.opening_cash, src) +
        row('+', 'Cash sales', f.cash_sales) +
        row('+', 'Debts paid in cash', f.debt_cash) +
        row('+', 'Change customers left with us', f.change_held) +
        row('−', 'Change handed back', f.change_paid) +
        row('−', 'Cash expenses &amp; supplier payments', f.expenses_cash, f.supplier_part_cash > 0 ? `includes ${money(f.supplier_part_cash)} for supplies` : '') +
        `<div class="row total"><span>Expected cash</span><span class="v3-money">${money(f.expected_cash)}</span></div>`;
    document.getElementById('calcOnline').innerHTML =
        `<div style="font:700 12px var(--font-body);letter-spacing:.08em;text-transform:uppercase;color:var(--ink-soft);">Mobile money</div>` +
        row('+', 'Mobile-money sales', f.online_sales) +
        row('+', 'Debts paid by mobile money', f.debt_online) +
        row('−', 'Expenses paid by mobile money', f.expenses_online) +
        `<div class="row total"><span>Expected mobile money</span><span class="v3-money">${money(f.expected_online)}</span></div>`;
    document.getElementById('calcInfo').innerHTML =
        `<div style="font:700 12px var(--font-body);letter-spacing:.08em;text-transform:uppercase;color:var(--ink-soft);">For information</div>` +
        row('', 'Total sales today', f.total_sales) + row('', 'Sold on credit (not in the till)', f.credit_sales);
}

function renderStatus() {
    const a = preview.audit, chip = document.getElementById('auditStatusChip'), banner = document.getElementById('auditBanner');
    const btn = document.getElementById('saveAuditBtn');
    if (a && !editingId) {
        const cls = a.status === 'balanced' ? 'good' : a.status === 'over' ? 'warn' : 'bad';
        chip.innerHTML = `<span class="v3-chip ${cls}">Audit saved · ${a.status}</span>`;
        banner.innerHTML = `<div class="v3-banner ${a.status === 'balanced' ? 'ok' : a.status === 'over' ? 'warn' : 'bad'}">
            <div>The audit for <b>${esc(a.audit_date)}</b> was saved by <b>${esc(a.recorded_by_name || '—')}</b>: counted <b>${money(a.counted_cash)}</b>, variance <b>${money(a.variance)}</b>.
            ${worker ? 'Only an admin can correct it.' : '<a href="#" id="editAuditLink">Correct this audit</a>'}</div></div>`;
        btn.disabled = true; btn.textContent = 'Already saved';
        document.querySelectorAll('#denomGrid input, #countedOnline, #auditNote').forEach(i => i.disabled = true);
        document.getElementById('countedOnline').value = a.counted_online;
        document.getElementById('auditNote').value = a.note || '';
        const link = document.getElementById('editAuditLink');
        if (link) link.addEventListener('click', e => { e.preventDefault(); startEdit(a); });
    } else {
        chip.innerHTML = editingId ? '<span class="v3-chip warn">Correcting a saved audit</span>' : '<span class="v3-chip">Not audited yet</span>';
        banner.innerHTML = editingId ? '' : (selDate() === adminToday() ? '' : `<div class="v3-banner warn">You are auditing <b>${esc(selDate())}</b>, not today.</div>`);
        btn.disabled = false; btn.textContent = editingId ? 'Save correction' : 'Save Cash Audit';
        document.querySelectorAll('#denomGrid input, #countedOnline, #auditNote').forEach(i => i.disabled = false);
        if (!editingId) { document.getElementById('countedOnline').value = 0; document.getElementById('auditNote').value = ''; }
    }
}

function recalc() {
    const f = preview.figures, c = counted();
    document.getElementById('countedTotal').textContent = money(c);
    const online = Number(document.getElementById('countedOnline').value || 0);
    const showSaved = preview.audit && !editingId;
    const cashCounted = showSaved ? preview.audit.counted_cash : c;
    const onlineCounted = showSaved ? preview.audit.counted_online : online;
    const cv = cashCounted - f.expected_cash, ov = onlineCounted - f.expected_online, v = cv + ov;
    const idle = !showSaved && c === 0 && online === 0;
    const cls = idle ? 'idle' : Math.abs(v) < 0.5 ? 'balanced' : v > 0 ? 'over' : 'short';
    const head = idle ? 'Count the cash to see the result' : cls === 'balanced' ? 'Balanced — every shilling accounted for' : cls === 'over' ? `Over by ${money(Math.abs(v))}` : `Short by ${money(Math.abs(v))}`;
    document.getElementById('resultBox').innerHTML = `
      <div class="v3-result ${cls}">
        <div class="big">${head}</div>
        <div class="small">${cls === 'short' ? 'Check the day’s sales, expenses and change before saving.' : cls === 'over' ? 'There is more money than the system expects — check for unrecorded expenses or a sale recorded too low.' : idle ? 'Enter the notes and coins in the drawer.' : 'Great work.'}</div>
        <div class="row2">
          <div>Cash<b>${money(cashCounted)} vs ${money(f.expected_cash)}</b></div>
          <div>Cash difference<b>${money(cv)}</b></div>
          <div>Mobile difference<b>${money(ov)}</b></div>
        </div>
      </div>`;
}

function payload() {
    const denominations = [...document.querySelectorAll('#denomGrid input')]
        .map(i => ({ denom: Number(i.dataset.denom), qty: Math.max(0, parseInt(i.value || '0', 10) || 0) })).filter(r => r.qty > 0);
    return { denominations, counted_online: Number(document.getElementById('countedOnline').value || 0), note: document.getElementById('auditNote').value.trim() };
}

async function saveAudit() {
    const box = document.getElementById('auditAlert'); box.innerHTML = '';
    const p = payload();
    if (!p.denominations.length) { box.innerHTML = '<div class="alert alert-error">Enter at least one note or coin count.</div>'; return; }
    const total = counted();
    const f = preview.figures, v = (total - f.expected_cash) + (p.counted_online - f.expected_online);
    if (Math.abs(v) >= 0.5 && !confirm(`The audit is ${v < 0 ? 'SHORT' : 'OVER'} by ${money(Math.abs(v))}. Save it anyway?`)) return;
    const btn = document.getElementById('saveAuditBtn'); btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving…';
    const body = editingId ? { ...p, id: editingId } : { ...p, audit_date: selDate() };
    const res = await adminApi('/cash_audit.php', { method: editingId ? 'PUT' : 'POST', body });
    if (!res.success) { box.innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; btn.disabled = false; btn.textContent = 'Save Cash Audit'; return; }
    adminToast(res.message, 'success');
    cancelEdit(); loadDay();
}

function startEdit(a) {
    editingId = a.id;
    buildDenoms(preview.denoms, a.denominations);
    document.getElementById('countedOnline').value = a.counted_online;
    document.getElementById('auditNote').value = a.note || '';
    document.getElementById('cancelEditBtn').classList.remove('v3-hidden');
    renderStatus(); recalc();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function cancelEdit() { editingId = null; document.getElementById('cancelEditBtn').classList.add('v3-hidden'); }

/* ---------------- float & deposits ---------------- */
async function loadFloatAndDeposits() {
    const d = selDate();
    document.getElementById('floatDateLbl').textContent = d;
    const [fl, dep] = await Promise.all([adminApi('/cash_float.php?date=' + d), adminApi('/bank_deposits.php?date=' + d)]);
    if (fl.success) {
        document.getElementById('floatTotal').textContent = money(fl.summary.total);
        document.getElementById('floatMoves').textContent = money(fl.summary.adds - fl.summary.reduces);
        document.getElementById('floatSource').textContent = fl.summary.base_source === 'carry_over' ? `opened with ${money(fl.summary.base)} from ${fl.summary.carry_over_from}` : fl.summary.base_source === 'typed' ? 'opening set by an admin' : 'no earlier audit to carry over';
        const names = { opening: 'Opening set', add: 'Added', reduce: 'Taken out' };
        document.getElementById('floatEntries').innerHTML = fl.entries.length ? fl.entries.map(e => `
          <tr><td><span class="v3-chip ${e.entry_type === 'reduce' ? 'bad' : 'good'}">${names[e.entry_type]}</span></td>
          <td class="v3-money">${money(e.amount)}</td><td>${esc(e.note || '')}<div class="v3-muted">${esc(e.recorded_by_name || '')}</div></td>
          <td>${worker ? '' : `<button class="icon-btn danger" onclick="delFloat(${e.id})">${adminIcon('trash', 14)}</button>`}</td></tr>`).join('')
            : '<tr><td colspan="4" class="v3-muted" style="padding:14px;">No float movements for this date.</td></tr>';
    }
    if (dep.success) {
        const chip = document.getElementById('bankableChip'), lock = document.getElementById('depositLock');
        if (dep.bankable === null) { chip.textContent = 'No audit yet'; lock.classList.remove('v3-hidden'); }
        else { chip.textContent = money(dep.bankable) + ' left to bank'; lock.classList.add('v3-hidden'); }
        document.getElementById('depositRows').innerHTML = dep.deposits.length ? dep.deposits.map(x => `
          <tr><td>${esc(x.deposit_date)}</td>
          <td class="v3-money">${money(x.amount)}</td><td class="v3-muted">${esc(x.note || '—')}</td>
          <td style="white-space:nowrap;">${x.has_receipt ? `<a class="icon-btn" target="_blank" href="${ADMIN_API_BASE}/bank_deposit_receipt.php?id=${x.id}" title="View slip">${adminIcon('eye', 14)}</a>` : ''}
          ${worker ? '' : `<button class="icon-btn danger" onclick="delDeposit(${x.id})">${adminIcon('trash', 14)}</button>`}</td></tr>`).join('')
            : '<tr><td colspan="4" class="v3-muted" style="padding:14px;">No deposits for this date.</td></tr>';
    }
}

async function saveFloat(e) {
    e.preventDefault();
    const box = document.getElementById('floatAlert'); box.innerHTML = '';
    const res = await adminApi('/cash_float.php', { method: 'POST', body: {
        float_date: selDate(), entry_type: document.getElementById('floatType').value,
        amount: Number(document.getElementById('floatAmount').value), note: document.getElementById('floatNote').value.trim() } });
    if (!res.success) { box.innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; return; }
    adminToast(res.message, 'success'); e.target.reset(); loadDay();
}
async function delFloat(id) {
    if (!confirm('Remove this float entry?')) return;
    const r = await adminApi('/cash_float.php?id=' + id, { method: 'DELETE' });
    adminToast(r.message, r.success ? 'success' : 'error'); if (r.success) loadDay();
}
async function saveDeposit(e) {
    e.preventDefault();
    const box = document.getElementById('depAlert'); box.innerHTML = '';
    const fd = new FormData();
    fd.append('deposit_date', selDate());
    fd.append('amount', document.getElementById('depAmount').value);
    fd.append('note', document.getElementById('depNote').value.trim());
    const f = document.getElementById('depFile').files[0]; if (f) fd.append('receipt', f);
    const btn = document.getElementById('depBtn'); btn.disabled = true;
    const res = await adminApi('/bank_deposits.php', { method: 'POST', body: fd, isForm: true });
    btn.disabled = false;
    if (!res.success) { box.innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; return; }
    adminToast(res.message, 'success'); e.target.reset(); loadDay();
}
async function delDeposit(id) {
    if (!confirm('Remove this deposit?')) return;
    const r = await adminApi('/bank_deposits.php?id=' + id, { method: 'DELETE' });
    adminToast(r.message, r.success ? 'success' : 'error'); if (r.success) loadDay();
}

/* ---------------- history ---------------- */
async function loadHistory() {
    const from = document.getElementById('histFrom').value, to = document.getElementById('histTo').value;
    const q = `?from=${from}&to=${to}`;
    document.getElementById('histExport').href = ADMIN_API_BASE + '/cash_audit.php' + q + '&action=export';
    const res = await adminApi('/cash_audit.php' + q);
    const tb = document.getElementById('histRows');
    if (!res.success) { tb.innerHTML = `<tr><td colspan="8">${esc(res.message || 'Error')}</td></tr>`; return; }
    const s = res.summary;
    document.getElementById('histStats').innerHTML = `
      <div class="v3-stat orange"><div class="lbl">Audits</div><div class="val">${s.audits}</div></div>
      <div class="v3-stat green"><div class="lbl">Balanced</div><div class="val">${s.balanced}</div></div>
      <div class="v3-stat red"><div class="lbl">Short</div><div class="val">${s.short}</div></div>
      <div class="v3-stat gold"><div class="lbl">Over</div><div class="val">${s.over}</div></div>
      <div class="v3-stat ${s.net_variance < 0 ? 'red' : 'green'}"><div class="lbl">Net variance</div><div class="val">${money(s.net_variance)}</div></div>`;
    tb.innerHTML = res.audits.length ? res.audits.map(a => `
      <tr><td><b>${esc(a.audit_date)}</b></td><td class="v3-money">${money(a.expected_cash)}</td><td class="v3-money">${money(a.counted_cash)}</td>
      <td class="v3-money">${money(a.counted_online)}</td>
      <td><span class="v3-chip ${a.status === 'balanced' ? 'good' : a.status === 'over' ? 'warn' : 'bad'}">${a.status === 'balanced' ? 'Balanced' : money(a.variance)}</span></td>
      <td class="v3-money">${money(a.bank_deposit)}</td><td>${esc(a.recorded_by_name || '—')}</td>
      <td style="white-space:nowrap;"><button class="icon-btn" onclick="viewAudit(${a.id})">${adminIcon('eye', 14)}</button>
        ${worker ? '' : `<button class="icon-btn danger" onclick="delAudit(${a.id})">${adminIcon('trash', 14)}</button>`}</td></tr>`).join('')
        : '<tr><td colspan="8" class="v3-empty">No audits in this range yet.</td></tr>';
}

async function viewAudit(id) {
    const res = await adminApi('/cash_audit.php?id=' + id);
    if (!res.success) return adminToast(res.message, 'error');
    const a = res.audit;
    document.getElementById('auditModalTitle').textContent = 'Cash audit — ' + a.audit_date;
    const L = (k, v, cls = '') => `<dt>${k}</dt><dd class="v3-money ${cls}">${v}</dd>`;
    document.getElementById('auditModalBody').innerHTML = `
      <div class="v3-result ${a.status}" style="margin:0 0 14px;"><div class="big">${a.status === 'balanced' ? 'Balanced' : (a.status === 'short' ? 'Short ' : 'Over ') + money(Math.abs(a.variance))}</div>
      <div class="small">Saved by ${esc(a.recorded_by_name || '—')} · ${esc(a.created_at)}</div></div>
      <div class="v3-grid-2"><dl class="v3-kv">
        ${L('Opening cash', money(a.opening_cash))}${L('Cash sales', money(a.cash_sales))}${L('Mobile sales', money(a.online_sales))}${L('Credit sales', money(a.credit_sales))}
        ${L('Debts (cash)', money(a.debt_cash))}${L('Debts (mobile)', money(a.debt_online))}${L('Expenses (cash)', money(a.expenses_cash))}${L('Expenses (mobile)', money(a.expenses_online))}
        ${L('Change held', money(a.change_held))}${L('Change paid back', money(a.change_paid))}</dl>
      <dl class="v3-kv">${L('Expected cash', money(a.expected_cash))}${L('Counted cash', money(a.counted_cash))}${L('Cash difference', money(a.cash_variance), a.cash_variance < 0 ? 'v3-neg' : '')}
        ${L('Expected mobile', money(a.expected_online))}${L('Counted mobile', money(a.counted_online))}${L('Mobile difference', money(a.online_variance), a.online_variance < 0 ? 'v3-neg' : '')}
        ${L('Banked', money(a.bank_deposit))}${L('Left in office', money(a.remaining_cash))}</dl></div>
      ${a.denominations.length ? `<h4 style="margin:16px 0 6px;font-size:13px;">Cash count</h4><div style="display:flex;flex-wrap:wrap;gap:8px;">${a.denominations.map(d => `<span class="v3-chip">${money(d.denom)} × ${d.qty}</span>`).join('')}</div>` : ''}
      ${a.note ? `<p class="v3-muted" style="margin-top:12px;">Note: ${esc(a.note)}</p>` : ''}
      ${a.deposits.length ? `<h4 style="margin:16px 0 6px;font-size:13px;">Bank deposits</h4>${a.deposits.map(d => `<div class="v3-muted">${money(d.amount)}${d.note ? ' — ' + esc(d.note) : ''}</div>`).join('')}` : ''}
      <div style="margin-top:16px;display:flex;gap:8px;"><button class="btn btn-outline btn-sm" onclick="window.print()">${adminIcon('printer', 14)} Print</button></div>`;
    adminOpenModal('auditModal');
}
async function delAudit(id) {
    if (!confirm('Delete this saved audit? This is recorded in the activity log.')) return;
    const r = await adminApi('/cash_audit.php?id=' + id, { method: 'DELETE' });
    adminToast(r.message, r.success ? 'success' : 'error'); if (r.success) { loadHistory(); loadDay(); }
}
