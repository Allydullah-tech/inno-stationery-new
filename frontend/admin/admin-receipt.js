/* Printable receipt for one sale (receipt.html?no=RC-XXXXXX). */
(async () => {
    const me = await guardAdminPage();
    if (!me) return;
    renderAdminShell('sales', 'Receipt', me);
    const no = new URLSearchParams(location.search).get('no');
    const box = document.getElementById('receiptBox');
    if (!no) { box.innerHTML = '<div class="c">No receipt number given.</div>'; return; }
    const res = await adminApi('/sales.php?receipt=' + encodeURIComponent(no));
    if (!res.success) { box.innerHTML = `<div class="c">${adminEscapeHtml(res.message || 'Receipt not found.')}</div>`; return; }
    const r = res.receipt, esc = adminEscapeHtml, m = adminFormatMoney;
    const credit = r.sale_type === 'credit';
    box.innerHTML = `
      <h2>${esc(res.shop.name)}</h2>
      <div class="c">SALES RECEIPT</div><hr>
      <table><tr><td>Receipt</td><td>${esc(r.receipt_no)}</td></tr><tr><td>Date</td><td>${esc(r.sale_date)}</td></tr>
      <tr><td>Served by</td><td>${esc(r.recorded_by_name || '—')}</td></tr>
      ${r.customer_name ? `<tr><td>Customer</td><td>${esc(r.customer_name)}</td></tr><tr><td>Phone</td><td>${esc(r.customer_phone || '')}</td></tr>` : ''}
      <tr><td>Payment</td><td>${esc(paymentMethodLabel(r.payment_method))}</td></tr></table><hr>
      <table>${r.lines.map(l => `<tr><td>${esc(l.product_name)}<br><span style="color:#777;">${l.quantity} × ${m(l.unit_price)}</span>${(l.materials || []).map(x => `<br><span style="color:#999;font-size:11px;">· ${esc(x.label)} ×${x.quantity}</span>`).join('')}</td><td>${m(l.total_amount)}</td></tr>`).join('')}
      <tr class="tot"><td>TOTAL</td><td>${m(r.total)}</td></tr></table>
      ${credit ? `<hr><table><tr><td>Balance due</td><td>${m(r.debt_balance ?? r.total)}</td></tr><tr><td>Pay by</td><td>${esc(r.debt_deadline || '')}</td></tr></table>` : ''}
      ${r.change_left > 0 ? `<hr><table><tr><td>Change kept for you</td><td>${m(r.change_left)}</td></tr></table>` : ''}
      ${r.notes ? `<hr><div>${esc(r.notes)}</div>` : ''}
      <div class="stamp ${credit ? 'credit' : ''}">${credit ? 'ON CREDIT' : 'PAID'}</div>
      <hr><div class="c">Thank you for shopping with us!</div>`;
})();
