/* Activity log viewer (admins only). */
const esc = adminEscapeHtml;
const VERB = { create: 'added', update: 'changed', delete: 'deleted', login: 'signed in' };
const ENTITY = { sale: 'a sale', expense: 'an expense', damage: 'a damage record', debt_payment: 'a debt payment', purchase: 'a stock purchase', service_purchase: 'a service purchase',
    service_waste: 'service material loss', cash_audit: 'a cash audit', cash_float: 'the cash float', bank_deposit: 'a bank deposit', customer: 'a customer', change_collection: 'customer change',
    product: 'a product', service: 'a service', account: 'an account', session: '' };

(async () => {
    const me = await guardAdminPage();
    if (!me || blockWorkers(me)) return;
    renderAdminShell('audit-log', 'Activity Log', me);
    document.getElementById('lFrom').value = adminToday(-7); document.getElementById('lTo').value = adminToday();
    document.getElementById('lGo').addEventListener('click', load);
    document.getElementById('lQ').addEventListener('input', adminDebounce(load, 350));
    load();
})();

async function load() {
    const p = new URLSearchParams({ from: document.getElementById('lFrom').value, to: document.getElementById('lTo').value, entity: document.getElementById('lEntity').value,
        action: document.getElementById('lAction').value, q: document.getElementById('lQ').value.trim(), limit: 300 });
    const res = await adminApi('/audit_log.php?' + p.toString());
    const box = document.getElementById('logList');
    if (!res.success) { box.innerHTML = `<div class="v3-muted">${esc(res.message || 'Could not load')}</div>`; return; }
    const sel = document.getElementById('lEntity'), cur = sel.value;
    if (sel.options.length <= 1) sel.innerHTML = '<option value="">Everything</option>' + res.entities.map(e => `<option value="${esc(e)}">${esc(e.replace(/_/g, ' '))}</option>`).join('');
    sel.value = cur;
    box.innerHTML = res.entries.length ? res.entries.map((e, i) => `
      <div class="v3-ev ${esc(e.action)}" style="animation-delay:${Math.min(i, 12) * 30}ms">
        <div class="h"><b>${esc(e.admin_name || 'System')}</b> <span class="v3-chip ${e.action === 'delete' ? 'bad' : e.action === 'update' ? 'warn' : e.action === 'login' ? 'info' : 'good'}">${VERB[e.action] || esc(e.action)} ${ENTITY[e.entity] ?? esc(e.entity)}</span></div>
        <div class="d">${esc(e.description || '')}</div>
        <div class="t">${esc(e.created_at)}${e.ip_address ? ' · ' + esc(e.ip_address) : ''}</div>
      </div>`).join('') : `<div class="v3-empty">${adminIcon('clipboard', 42)}<div>Nothing was recorded for these filters.</div></div>`;
}
