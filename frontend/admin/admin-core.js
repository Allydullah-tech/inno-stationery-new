/* ==========================================================================
   INNO's STATIONERY (Internal System) — admin-core.js
   Shared across all pages: session guard, sidebar shell, API helper,
   icon set, role-based navigation (workers see a reduced menu).
   ========================================================================== */

const ADMIN_ICON_PATHS = {
    'bar-chart': '<line x1="5" y1="20" x2="5" y2="12"/><line x1="12" y1="20" x2="12" y2="6"/><line x1="19" y1="20" x2="19" y2="15"/>',
    package: '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><line x1="12" y1="13" x2="12" y2="22"/>',
    cart: '<circle cx="9" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/><path d="M2 3h2l2.6 12.6a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20 8H6"/>',
    wallet: '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="15" r="1.2" fill="currentColor" stroke="none"/>',
    alert: '<path d="M12 3l10 18H2z"/><line x1="12" y1="10" x2="12" y2="15"/><circle cx="12" cy="18" r="0.8" fill="currentColor" stroke="none"/>',
    truck: '<rect x="1" y="7" width="14" height="9"/><path d="M15 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
    'file-text': '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v4h4"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
    gear: '<circle cx="12" cy="12" r="3.2"/><path d="M19 12a7 7 0 0 0-.2-1.6l2-1.6-2-3.4-2.4 1a7 7 0 0 0-2.8-1.6L13 2h-2l-.6 2.8a7 7 0 0 0-2.8 1.6l-2.4-1-2 3.4 2 1.6A7 7 0 0 0 5 12c0 .5 0 1.1.2 1.6l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 2.8 1.6L11 22h2l.6-2.8a7 7 0 0 0 2.8-1.6l2.4 1 2-3.4-2-1.6c.2-.5.2-1.1.2-1.6z"/>',
    eye: '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off': '<path d="M17.9 17.9A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 4.22-5.06M9.9 4.24A9.13 9.13 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
    check: '<polyline points="4 12 9 17 20 6"/>',
    edit: '<path d="M3 21l1.5-5.5L16 4l4 4L8.5 19.5 3 21z"/><line x1="14" y1="6" x2="18" y2="10"/>',
    trash: '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
    download: '<path d="M12 3v13"/><polyline points="7 11 12 16 17 11"/><line x1="4" y1="20" x2="20" y2="20"/>',
    upload: '<path d="M12 21V8"/><polyline points="7 13 12 8 17 13"/><line x1="4" y1="4" x2="20" y2="4"/>',
    plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    'credit-card': '<rect x="1.5" y="5" width="21" height="14" rx="2.2"/><line x1="1.5" y1="10" x2="22.5" y2="10"/><line x1="5" y1="15" x2="10" y2="15"/>',
    search: '<circle cx="10.5" cy="10.5" r="6.5"/><line x1="20" y1="20" x2="15.5" y2="15.5"/>',
    wrench: '<path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 0 1 5.4-5.4l-3-3z"/><path d="M4 20l-1-1 3-3"/>',
};

function adminIcon(name, size = 17) {
    const paths = ADMIN_ICON_PATHS[name] || '';
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;flex-shrink:0;">${paths}</svg>`;
}

const ADMIN_API_BASE = '../../backend/api';

async function adminApi(path, { method = 'GET', body = null, isForm = false } = {}) {
    const opts = { method, credentials: 'include', headers: {} };
    if (body && !isForm) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    } else if (body && isForm) {
        opts.body = body;
    }
    let res, data;
    try {
        res = await fetch(ADMIN_API_BASE + path, opts);
        data = await res.json();
    } catch (e) {
        return { success: false, message: 'Network error.' };
    }
    if (res.status === 401 && !path.includes('admin_auth')) {
        window.location.href = 'login.html';
    }
    return data;
}

function adminFormatMoney(amount) {
    const n = Number(amount) || 0;
    return 'TSh ' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function adminEscapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ? ? '';
    return div.innerHTML;
}

function adminToast(message, type = 'info', duration = 3500) {
    let wrap = document.querySelector('.toast-wrap');
    if (!wrap) { wrap = document.createElement('div');
        wrap.className = 'toast-wrap';
        document.body.appendChild(wrap); }
    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.textContent = message;
    wrap.appendChild(el);
    setTimeout(() => { el.style.opacity = '0';
        el.style.transition = 'opacity .3s';
        setTimeout(() => el.remove(), 300); }, duration);
}

// Full nav list; workers only see items where workerAllowed is true.
const NAV_ITEMS = [
    { href: 'dashboard.html', icon: 'bar-chart', label: 'Dashboard', key: 'dashboard', workerAllowed: true },
    { href: 'stock.html', icon: 'package', label: 'Stock', key: 'stock', workerAllowed: true },
    { href: 'services.html', icon: 'wrench', label: 'Services', key: 'services', workerAllowed: true },
    { href: 'sales.html', icon: 'cart', label: 'Sales', key: 'sales', workerAllowed: true },
    { href: 'debts.html', icon: 'credit-card', label: 'Debts / Credit', key: 'debts', workerAllowed: true },
    { href: 'expenses.html', icon: 'wallet', label: 'Expenses', key: 'expenses', workerAllowed: true },
    { href: 'damages.html', icon: 'alert', label: 'Damages', key: 'damages', workerAllowed: true },
    { href: 'purchases.html', icon: 'truck', label: 'Purchases', key: 'purchases', workerAllowed: false },
    { href: 'reports.html', icon: 'file-text', label: 'Reports', key: 'reports', workerAllowed: false },
    { href: 'admins.html', icon: 'user', label: 'Admins & Workers', key: 'admins', workerAllowed: false },
    { href: 'settings.html', icon: 'gear', label: 'Settings', key: 'settings', workerAllowed: false },
];

/** Guards a page: redirects to login if not authenticated. Returns admin info. */
async function guardAdminPage() {
    const res = await adminApi('/admin_auth.php?action=session');
    if (!res.authenticated) {
        window.location.href = 'login.html';
        return null;
    }
    return res.admin;
}

/** Guards a page that workers must NOT access. Redirects them to the dashboard. */
function blockWorkers(admin) {
    if (admin.role === 'worker') {
        window.location.href = 'dashboard.html';
        return true;
    }
    return false;
}

/** Renders the sidebar + topbar shell, hiding worker-restricted nav items for workers. */
function renderAdminShell(activeKey, pageTitle, admin) {
    const sidebarWrap = document.getElementById('adminSidebarWrap');
    const topbar = document.getElementById('adminTopbar');
    const visibleItems = NAV_ITEMS.filter(item => admin.role !== 'worker' || item.workerAllowed);

    if (sidebarWrap) {
        sidebarWrap.innerHTML = `
        <aside class="admin-sidebar" id="adminSidebar">
          <div class="brand-row">
            <span class="mark">I</span>
            <span class="brand-text">INNO's<br><small>Internal System</small></span>
          </div>
          <nav class="admin-nav">
            ${visibleItems.map(item => `
              <a href="${item.href}" class="${activeKey === item.key ? 'active' : ''}">
                <span class="nav-icon">${adminIcon(item.icon, 16)}</span> ${item.label}
              </a>`).join('')}
          </nav>
          <div class="admin-sidebar-foot">
            <div class="admin-user-chip">
              <span class="av">${(admin?.full_name || 'A').charAt(0).toUpperCase()}</span>
              <div>
                <div class="name">${adminEscapeHtml(admin?.full_name || '')}</div>
                <div class="role">${adminEscapeHtml(admin?.role?.replace('_',' ') || '')}</div>
              </div>
            </div>
            <div class="logout-link" id="logoutLink">Log out</div>
          </div>
        </aside>`;
        document.getElementById('logoutLink').addEventListener('click', doLogout);
    }

    if (topbar) {
        topbar.innerHTML = `
        <div style="display:flex;align-items:center;gap:14px;">
          <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu"><span></span><span></span><span></span></button>
          <h1>${pageTitle}</h1>
        </div>`;

        if (!document.getElementById('sidebarBackdrop')) {
            const backdrop = document.createElement('div');
            backdrop.id = 'sidebarBackdrop';
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
        }
        const backdropEl = document.getElementById('sidebarBackdrop');
        const sidebarEl = document.getElementById('adminSidebar');
        const menuBtnEl = document.getElementById('mobileMenuBtn');

        const openSidebar = () => { sidebarEl.classList.add('open'); backdropEl.classList.add('open'); menuBtnEl.classList.add('open'); };
        const closeSidebar = () => { sidebarEl.classList.remove('open'); backdropEl.classList.remove('open'); menuBtnEl.classList.remove('open'); };
        const toggleSidebar = () => { sidebarEl.classList.contains('open') ? closeSidebar() : openSidebar(); };

        menuBtnEl.addEventListener('click', toggleSidebar);
        backdropEl.addEventListener('click', closeSidebar);
        sidebarEl.querySelectorAll('.admin-nav a').forEach(a => a.addEventListener('click', closeSidebar));
    }
}

async function doLogout() {
    await adminApi('/admin_auth.php?action=logout', { method: 'POST' });
    window.location.href = 'login.html';
}

const STATUS_LABEL_MAP = {
    active: 'Active', inactive: 'Inactive',
    expired: 'Expired', damaged: 'Damaged', other: 'Other',
};

// Payment method display names, shared by the Sales and Debts pages.
const PAYMENT_METHOD_LABELS = {
    cash_in_hand: 'Cash in Hand',
    cash: 'Cash',
    mpesa: 'M-PESA',
    airtel_money: 'Airtel Money',
    halopesa: 'HaloPesa',
    mixx_by_yas: 'Mixx by Yas',
    credit: 'Credit',
};
function paymentMethodLabel(method) {
    return PAYMENT_METHOD_LABELS[method] || (method ? adminEscapeHtml(method) : '—');
}

// Debt/credit status display names + pill CSS class.
const DEBT_STATUS_LABELS = { pending: 'Pending', partial: 'Partially Paid', paid: 'Paid', overdue: 'Overdue' };
const DEBT_STATUS_CLASS = { pending: 'status-pending', partial: 'status-partial', paid: 'status-paid-debt', overdue: 'status-overdue' };
function debtStatusPill(status) {
    const label = DEBT_STATUS_LABELS[status] || status;
    const cls = DEBT_STATUS_CLASS[status] || '';
    return `<span class="status-pill ${cls}">${label}</span>`;
}

/**
 * Wraps a password <input> with a show/hide eye toggle button.
 * Call after the input exists in the DOM: addPasswordToggle(document.getElementById('password'))
 */
function addPasswordToggle(inputEl) {
    if (!inputEl || inputEl.dataset.toggleAdded) return;
    inputEl.dataset.toggleAdded = '1';

    const wrap = document.createElement('div');
    wrap.className = 'password-field-wrap';
    inputEl.parentNode.insertBefore(wrap, inputEl);
    wrap.appendChild(inputEl);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle-btn';
    btn.setAttribute('aria-label', 'Show password');
    btn.innerHTML = adminIcon('eye', 17);
    wrap.appendChild(btn);

    btn.addEventListener('click', () => {
        const showing = inputEl.type === 'text';
        inputEl.type = showing ? 'password' : 'text';
        btn.innerHTML = adminIcon(showing ? 'eye' : 'eye-off', 17);
        btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
}

/**
 * Same searchable autocomplete as attachProductSearch, but for the
 * Services catalogue — shows the selling price instead of a stock count.
 */
function attachServiceSearch(inputEl, suggestionsEl, getServices, onSelect) {
    let highlightedIndex = -1;
    let currentMatches = [];

    function render(matches) {
        currentMatches = matches;
        highlightedIndex = -1;
        if (!matches.length) {
            suggestionsEl.innerHTML = `<div class="product-search-empty">No matching services. Add it first from the Services page.</div>`;
        } else {
            suggestionsEl.innerHTML = matches.map((s, i) => `
              <div class="product-search-item" data-index="${i}">
                <span>${adminEscapeHtml(s.name)}</span>
                <span class="psi-stock">${adminFormatMoney(s.selling_price)}</span>
              </div>
            `).join('');
        }
        suggestionsEl.classList.add('open');
    }

    inputEl.addEventListener('input', () => {
        const q = inputEl.value.trim().toLowerCase();
        inputEl.dataset.selectedId = '';
        if (!q) { suggestionsEl.classList.remove('open'); return; }
        const matches = getServices().filter(s => s.name.toLowerCase().includes(q)).slice(0, 15);
        render(matches);
    });

    inputEl.addEventListener('focus', () => {
        if (inputEl.value.trim()) {
            const q = inputEl.value.trim().toLowerCase();
            render(getServices().filter(s => s.name.toLowerCase().includes(q)).slice(0, 15));
        }
    });

    inputEl.addEventListener('keydown', (e) => {
        if (!suggestionsEl.classList.contains('open') || !currentMatches.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightedIndex = Math.min(highlightedIndex + 1, currentMatches.length - 1);
            updateHighlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightedIndex = Math.max(highlightedIndex - 1, 0);
            updateHighlight();
        } else if (e.key === 'Enter' && highlightedIndex >= 0) {
            e.preventDefault();
            selectService(currentMatches[highlightedIndex]);
        } else if (e.key === 'Escape') {
            suggestionsEl.classList.remove('open');
        }
    });

    function updateHighlight() {
        suggestionsEl.querySelectorAll('.product-search-item').forEach((el, i) => {
            el.classList.toggle('highlighted', i === highlightedIndex);
        });
    }

    suggestionsEl.addEventListener('click', (e) => {
        const item = e.target.closest('.product-search-item');
        if (!item) return;
        selectService(currentMatches[Number(item.dataset.index)]);
    });

    function selectService(service) {
        inputEl.value = service.name;
        inputEl.dataset.selectedId = service.id;
        suggestionsEl.classList.remove('open');
        onSelect(service);
    }

    document.addEventListener('click', (e) => {
        if (!inputEl.contains(e.target) && !suggestionsEl.contains(e.target)) {
            suggestionsEl.classList.remove('open');
        }
    });
}
function attachProductSearch(inputEl, suggestionsEl, getProducts, onSelect) {
    let highlightedIndex = -1;
    let currentMatches = [];

    function render(matches) {
        currentMatches = matches;
        highlightedIndex = -1;
        if (!matches.length) {
            suggestionsEl.innerHTML = `<div class="product-search-empty">No matching products. You can still type a new product name.</div>`;
        } else {
            suggestionsEl.innerHTML = matches.map((p, i) => `
              <div class="product-search-item" data-index="${i}">
                <span>${adminEscapeHtml(p.name)}</span>
                <span class="psi-stock">${p.stock} in stock</span>
              </div>
            `).join('');
        }
        suggestionsEl.classList.add('open');
    }

    inputEl.addEventListener('input', () => {
        const q = inputEl.value.trim().toLowerCase();
        inputEl.dataset.selectedId = '';
        if (!q) { suggestionsEl.classList.remove('open'); return; }
        const matches = getProducts().filter(p => p.name.toLowerCase().includes(q)).slice(0, 15);
        render(matches);
    });

    inputEl.addEventListener('focus', () => {
        if (inputEl.value.trim()) {
            const q = inputEl.value.trim().toLowerCase();
            render(getProducts().filter(p => p.name.toLowerCase().includes(q)).slice(0, 15));
        }
    });

    inputEl.addEventListener('keydown', (e) => {
        if (!suggestionsEl.classList.contains('open') || !currentMatches.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightedIndex = Math.min(highlightedIndex + 1, currentMatches.length - 1);
            updateHighlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightedIndex = Math.max(highlightedIndex - 1, 0);
            updateHighlight();
        } else if (e.key === 'Enter' && highlightedIndex >= 0) {
            e.preventDefault();
            selectProduct(currentMatches[highlightedIndex]);
        } else if (e.key === 'Escape') {
            suggestionsEl.classList.remove('open');
        }
    });

    function updateHighlight() {
        suggestionsEl.querySelectorAll('.product-search-item').forEach((el, i) => {
            el.classList.toggle('highlighted', i === highlightedIndex);
        });
    }

    suggestionsEl.addEventListener('click', (e) => {
        const item = e.target.closest('.product-search-item');
        if (!item) return;
        selectProduct(currentMatches[Number(item.dataset.index)]);
    });

    function selectProduct(product) {
        inputEl.value = product.name;
        inputEl.dataset.selectedId = product.id;
        suggestionsEl.classList.remove('open');
        onSelect(product);
    }

    document.addEventListener('click', (e) => {
        if (!inputEl.contains(e.target) && !suggestionsEl.contains(e.target)) {
            suggestionsEl.classList.remove('open');
        }
    });
}