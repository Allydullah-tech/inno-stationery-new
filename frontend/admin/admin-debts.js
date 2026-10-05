/* Debts / Credit page logic */
let currentAdmin = null;
let currentDebtId = null;
let searchDebounceTimer = null;

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    currentAdmin = admin;
    renderAdminShell('debts', 'Debts / Credit', admin);

    loadDebts();

    // Real search-as-you-type (debounced) rather than a plain dropdown-only filter.
    document.getElementById('debtSearch').addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(loadDebts, 300);
    });
    document.getElementById('debtStatusFilter').addEventListener('change', loadDebts);
    document.getElementById('debtPeriodFilter').addEventListener('change', () => {
        document.getElementById('debtCustomRangeWrap').style.display = document.getElementById('debtPeriodFilter').value === 'custom' ? 'inline-flex' : 'none';
    });
    document.getElementById('debtFilterBtn').addEventListener('click', loadDebts);

    document.getElementById('debtModalClose').addEventListener('click', closeDebtModal);
    document.getElementById('debtModalBackdrop').addEventListener('click', (e) => {
        if (e.target === document.getElementById('debtModalBackdrop')) closeDebtModal();
    });

    document.getElementById('showPaymentFormBtn').addEventListener('click', () => {
        document.getElementById('debtRecordPaymentWrap').style.display = 'none';
        document.getElementById('paymentForm').style.display = '';
    });
    document.getElementById('cancelPaymentBtn').addEventListener('click', () => {
        document.getElementById('paymentForm').style.display = 'none';
        document.getElementById('debtRecordPaymentWrap').style.display = '';
        document.getElementById('paymentAlert').innerHTML = '';
    });
    document.getElementById('paymentForm').addEventListener('submit', recordPayment);
})();

async function loadDebts() {
    const tbody = document.getElementById('debtsTbody');
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:30px;">Loading…</td></tr>`;

    const q = document.getElementById('debtSearch').value.trim();
    const status = document.getElementById('debtStatusFilter').value;
    const period = document.getElementById('debtPeriodFilter').value;
    let query = '?';
    if (q) query += `q=${encodeURIComponent(q)}&`;
    if (status) query += `status=${encodeURIComponent(status)}&`;
    if (period && period !== 'all') {
        query += `period=${period}&`;
        if (period === 'custom') {
            const from = document.getElementById('debtFrom').value;
            const to = document.getElementById('debtTo').value;
            if (!from || !to) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:34px;color:var(--ink-soft);">Please choose both a from and to date for a custom range.</td></tr>`;
                renderSummary({ total_outstanding: 0, total_overdue: 0, total_collected: 0, count_open: 0 });
                return;
            }
            query += `from=${from}&to=${to}&`;
        }
    }

    const res = await adminApi('/debts.php' + query);
    if (!res.success) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:30px;color:var(--ink-soft);">Could not load debts.</td></tr>`;
        return;
    }

    renderSummary(res.summary);

    const debts = res.debts;
    if (!debts.length) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:34px;color:var(--ink-soft);">No matching credit sales found.</td></tr>`;
        return;
    }

    tbody.innerHTML = debts.map(d => {
        const pct = d.original_amount > 0 ? Math.min(100, Math.round((d.amount_paid / d.original_amount) * 100)) : 0;
        return `
      <tr>
        <td><strong>${adminEscapeHtml(d.customer_name)}</strong></td>
        <td>${adminEscapeHtml(d.customer_phone)}</td>
        <td class="mono">${adminFormatMoney(d.original_amount)}</td>
        <td class="mono">
          ${adminFormatMoney(d.amount_paid)}
          <div class="balance-bar-track"><div class="balance-bar-fill" style="width:${pct}%;"></div></div>
        </td>
        <td class="mono">${adminFormatMoney(d.balance)}</td>
        <td>${d.payment_deadline}</td>
        <td>${d.sale_date}</td>
        <td>${debtStatusPill(d.status)}</td>
        <td><button class="btn btn-outline btn-sm" onclick="openDebtModal(${d.id})">View</button></td>
      </tr>
    `;
    }).join('');
}

function renderSummary(summary) {
    document.getElementById('debtSummaryGrid').innerHTML = `
      <div class="debt-summary-card warn">
        <div class="dsc-label">Total Outstanding</div>
        <div class="dsc-value">${adminFormatMoney(summary.total_outstanding)}</div>
      </div>
      <div class="debt-summary-card ${summary.total_overdue > 0 ? 'warn' : ''}">
        <div class="dsc-label">Overdue</div>
        <div class="dsc-value">${adminFormatMoney(summary.total_overdue)}</div>
      </div>
      <div class="debt-summary-card good">
        <div class="dsc-label">Total Collected</div>
        <div class="dsc-value">${adminFormatMoney(summary.total_collected)}</div>
      </div>
      <div class="debt-summary-card">
        <div class="dsc-label">Open Credit Accounts</div>
        <div class="dsc-value">${summary.count_open}</div>
      </div>
    `;
}

async function openDebtModal(id) {
    currentDebtId = id;
    document.getElementById('debtModalTitle').textContent = 'Debt details';
    document.getElementById('debtDetailGrid').innerHTML = `<div class="ddg-item"><div class="ddg-value">Loading…</div></div>`;
    document.querySelector('#paymentHistoryTable tbody').innerHTML = '';
    document.getElementById('paymentForm').style.display = 'none';
    document.getElementById('debtRecordPaymentWrap').style.display = '';
    document.getElementById('paymentAlert').innerHTML = '';
    document.getElementById('payDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('debtModalBackdrop').classList.add('open');

    await refreshDebtDetail();
}

async function refreshDebtDetail() {
    const res = await adminApi('/debts.php?id=' + currentDebtId);
    if (!res.success) {
        adminToast(res.message || 'Could not load debt.', 'error');
        closeDebtModal();
        return;
    }
    const d = res.debt;
    document.getElementById('debtModalTitle').textContent = d.customer_name + ' — Credit Sale';

    document.getElementById('debtDetailGrid').innerHTML = `
      <div class="ddg-item"><div class="ddg-label">Customer Name</div><div class="ddg-value">${adminEscapeHtml(d.customer_name)}</div></div>
      <div class="ddg-item"><div class="ddg-label">Phone Number</div><div class="ddg-value">${adminEscapeHtml(d.customer_phone)}</div></div>
      <div class="ddg-item"><div class="ddg-label">Original Debt</div><div class="ddg-value">${adminFormatMoney(d.original_amount)}</div></div>
      <div class="ddg-item"><div class="ddg-label">Amount Paid</div><div class="ddg-value">${adminFormatMoney(d.amount_paid)}</div></div>
      <div class="ddg-item"><div class="ddg-label">Remaining Balance</div><div class="ddg-value">${adminFormatMoney(d.balance)}</div></div>
      <div class="ddg-item"><div class="ddg-label">Payment Deadline</div><div class="ddg-value">${d.payment_deadline}</div></div>
      <div class="ddg-item"><div class="ddg-label">Sale Date</div><div class="ddg-value">${d.sale_date}</div></div>
      <div class="ddg-item"><div class="ddg-label">Status</div><div class="ddg-value">${debtStatusPill(d.status)}</div></div>
      ${d.notes ? `<div class="ddg-item" style="grid-column:1/-1;"><div class="ddg-label">Notes</div><div class="ddg-value" style="font-weight:600;">${adminEscapeHtml(d.notes)}</div></div>` : ''}
    `;

    document.getElementById('payAmount').max = d.balance;
    document.getElementById('payBalanceHint').textContent = `Remaining balance: ${adminFormatMoney(d.balance)}`;

    const isFullyPaid = d.status === 'paid';
    document.getElementById('showPaymentFormBtn').style.display = isFullyPaid ? 'none' : '';
    document.getElementById('debtRecordPaymentWrap').innerHTML = isFullyPaid
        ? `<div class="alert alert-success">This debt has been fully paid. No further payments are needed.</div>`
        : `<button class="btn btn-primary btn-block" id="showPaymentFormBtn">+ Record Payment</button>`;
    // Re-bind since innerHTML replaced the button node.
    const btn = document.getElementById('showPaymentFormBtn');
    if (btn) {
        btn.addEventListener('click', () => {
            document.getElementById('debtRecordPaymentWrap').style.display = 'none';
            document.getElementById('paymentForm').style.display = '';
        });
    }

    const payTbody = document.querySelector('#paymentHistoryTable tbody');
    payTbody.innerHTML = d.payments.length
        ? d.payments.map(p => `
            <tr>
              <td>${p.payment_date}</td>
              <td class="mono">${adminFormatMoney(p.amount)}</td>
              <td>${paymentMethodLabel(p.payment_method)}</td>
              <td>${adminEscapeHtml(p.reference_no || '—')}</td>
              <td>${adminEscapeHtml(p.notes || '—')}</td>
            </tr>
          `).join('')
        : `<tr><td colspan="5" style="text-align:center;color:var(--ink-soft);padding:16px;">No payments recorded yet.</td></tr>`;
}

function closeDebtModal() {
    document.getElementById('debtModalBackdrop').classList.remove('open');
    currentDebtId = null;
}

async function recordPayment(e) {
    e.preventDefault();
    const alertBox = document.getElementById('paymentAlert');
    alertBox.innerHTML = '';

    const amount = Number(document.getElementById('payAmount').value);
    const paymentDate = document.getElementById('payDate').value;
    const method = document.querySelector('input[name="payMethod"]:checked').value;
    const reference = document.getElementById('payReference').value.trim();
    const notes = document.getElementById('payNotes').value.trim();

    if (!amount || amount <= 0) {
        alertBox.innerHTML = `<div class="alert alert-error">Please enter a valid payment amount.</div>`;
        return;
    }

    const btn = document.getElementById('savePaymentBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    const res = await adminApi('/debts.php', {
        method: 'POST',
        body: {
            debt_id: currentDebtId,
            amount, payment_date: paymentDate, payment_method: method,
            reference_no: reference, notes,
        },
    });

    btn.disabled = false;
    btn.textContent = 'Save Payment';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    alertBox.innerHTML = `<div class="alert alert-success">${adminEscapeHtml(res.message)}</div>`;
    adminToast(res.message || 'Payment recorded.', 'success');
    document.getElementById('paymentForm').reset();
    document.getElementById('payDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('paymentForm').style.display = 'none';
    document.getElementById('debtRecordPaymentWrap').style.display = '';

    await refreshDebtDetail();
    loadDebts();
}
