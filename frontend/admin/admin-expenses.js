/* Expenses page logic */
let currentAdmin = null;

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    currentAdmin = admin;
    renderAdminShell('expenses', 'Expenses', admin);

    document.getElementById('expDate').value = adminToday();
    document.getElementById('expDate').max = adminToday();
    if (currentAdmin.role === 'worker') document.getElementById('expDate').disabled = true;
    document.getElementById('eSave').addEventListener('click', saveExpenseEdit);

    loadExpenses();
    document.getElementById('expenseForm').addEventListener('submit', recordExpense);
    document.getElementById('filterBtn').addEventListener('click', loadExpenses);
    document.getElementById('expSearch').addEventListener('input', () => {
        clearTimeout(window._expSearchTimer);
        window._expSearchTimer = setTimeout(loadExpenses, 350);
    });
})();

async function recordExpense(e) {
    e.preventDefault();
    const alertBox = document.getElementById('expenseAlert');
    alertBox.innerHTML = '';

    const btn = document.getElementById('expenseSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    const res = await adminApi('/expenses.php', {
        method: 'POST',
        body: {
            description: document.getElementById('expDescription').value.trim(),
            category: document.getElementById('expCategory').value.trim(),
            amount: Number(document.getElementById('expAmount').value),
            expense_date: document.getElementById('expDate').value,
            paid_from: document.getElementById('expPaid').value,
        },
    });

    btn.disabled = false;
    btn.textContent = 'Record Expense';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    adminToast('Expense recorded.', 'success');
    document.getElementById('expenseForm').reset();
    document.getElementById('expDate').value = adminToday();
    loadExpenses();
}

async function loadExpenses() {
    const tbody = document.getElementById('expensesTbody');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    const search = document.getElementById('expSearch').value.trim();
    let query = '?limit=1000&';
    if (from) query += `from=${from}&`;
    if (to) query += `to=${to}&`;
    if (search) query += `search=${encodeURIComponent(search)}&`;

    const res = await adminApi('/expenses.php' + query);
    const expenses = res.success ? res.expenses : [];

    if (!expenses.length) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:34px;color:var(--ink-soft);">No expense records found${search || from || to ? ' for the current filters' : ' yet'}.</td></tr>`;
        return;
    }

    const isWorker = currentAdmin.role === 'worker';

    tbody.innerHTML = expenses.map(e => `
      <tr>
        <td>${e.expense_date}</td>
        <td>${adminEscapeHtml(e.description)}</td>
        <td>${adminEscapeHtml(e.category || '—')}</td>
        <td class="mono">${adminFormatMoney(e.amount)}</td>
        <td>${({ cash: 'Till cash', online: 'Mobile money', other: 'Bank / other' })[e.paid_from] || '—'}</td>
        <td>${adminEscapeHtml(e.recorded_by_name || '—')}</td>
        <td style="white-space:nowrap;">${isWorker ? '' : `<button class="icon-btn" onclick='openExpenseEdit(${JSON.stringify(e).replace(/'/g, "&#39;")})' aria-label="Edit">${adminIcon('edit', 14)}</button> <button class="icon-btn danger" onclick="deleteExpense(${e.id})" aria-label="Delete">${adminIcon('trash', 14)}</button>`}</td>
      </tr>
    `).join('');
}

async function deleteExpense(id) {
    if (!confirm('Delete this expense record?')) return;
    const res = await adminApi('/expenses.php?id=' + id, { method: 'DELETE' });
    if (res.success) { adminToast('Expense deleted.', 'success'); loadExpenses(); }
    else adminToast(res.message || 'Could not delete.', 'error');
}

function openExpenseEdit(e) {
    document.getElementById('eId').value = e.id; document.getElementById('eDesc').value = e.description; document.getElementById('eCat').value = e.category || '';
    document.getElementById('eAmt').value = e.amount; document.getElementById('eDate').value = e.expense_date; document.getElementById('eDate').max = adminToday();
    document.getElementById('ePaid').value = e.paid_from || 'cash'; document.getElementById('eAlert').innerHTML = ''; adminOpenModal('expModal');
}
async function saveExpenseEdit() {
    const res = await adminApi('/expenses.php', { method: 'PUT', body: { id: Number(document.getElementById('eId').value), description: document.getElementById('eDesc').value.trim(),
        category: document.getElementById('eCat').value.trim(), amount: Number(document.getElementById('eAmt').value), expense_date: document.getElementById('eDate').value, paid_from: document.getElementById('ePaid').value } });
    if (!res.success) { document.getElementById('eAlert').innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`; return; }
    adminToast(res.message, 'success'); adminCloseModal('expModal'); loadExpenses();
}
