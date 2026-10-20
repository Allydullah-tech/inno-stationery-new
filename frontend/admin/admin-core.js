/* ==========================================================================
   INNO's STATIONERY (Internal System) — admin-core.js
   Shared across all pages: session guard, sidebar shell, API helper,
   icon set, role-based navigation (workers see a reduced menu).
   ========================================================================== */

const ADMIN_ICON_PATHS = {
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.9" y1="4.9" x2="6.3" y2="6.3"/><line x1="17.7" y1="17.7" x2="19.1" y2="19.1"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.9" y1="19.1" x2="6.3" y2="17.7"/><line x1="17.7" y1="6.3" x2="19.1" y2="4.9"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.6 3-6 7-6s7 2.4 7 6"/><circle cx="17.5" cy="9" r="2.5"/><path d="M17 14c3 0 5 1.8 5 5"/>',
    bank: '<path d="M3 10l9-6 9 6"/><line x1="5" y1="10" x2="19" y2="10"/><line x1="6" y1="13" x2="6" y2="18"/><line x1="10" y1="13" x2="10" y2="18"/><line x1="14" y1="13" x2="14" y2="18"/><line x1="18" y1="13" x2="18" y2="18"/><line x1="3" y1="21" x2="21" y2="21"/>',
    calculator: '<rect x="5" y="2" width="14" height="20" rx="2"/><rect x="8" y="5" width="8" height="4"/><line x1="9" y1="13" x2="9.01" y2="13"/><line x1="12" y1="13" x2="12.01" y2="13"/><line x1="15" y1="13" x2="15.01" y2="13"/><line x1="9" y1="17" x2="9.01" y2="17"/><line x1="12" y1="17" x2="12.01" y2="17"/><line x1="15" y1="17" x2="15.01" y2="17"/>',
    clipboard: '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1"/><line x1="9" y1="10" x2="15" y2="10"/><line x1="9" y1="14" x2="15" y2="14"/>',
    receipt: '<path d="M5 2h14v20l-3-2-2 2-2-2-2 2-2-2-3 2z"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/>',
    printer: '<path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="2"/><rect x="7" y="14" width="10" height="7"/>',
    x: '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
    shield: '<path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z"/><polyline points="9 12 11.5 14.5 15.5 9.5"/>',
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
    'trending-up': '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/>',
    layers: '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
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
    div.textContent = str ?? '';
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
    { href: 'sales.html', icon: 'cart', label: 'Sales', key: 'sales', workerAllowed: true },
    { href: 'customers.html', icon: 'users', label: 'Customers', key: 'customers', workerAllowed: true },
    { href: 'debts.html', icon: 'credit-card', label: 'Debts / Credit', key: 'debts', workerAllowed: true },
    { href: 'stock.html', icon: 'package', label: 'Stock', key: 'stock', workerAllowed: true },
    { href: 'services.html', icon: 'wrench', label: 'Services', key: 'services', workerAllowed: true },
    { href: 'service-purchases.html', icon: 'layers', label: 'Service Materials', key: 'service-purchases', workerAllowed: true },
    { href: 'purchases.html', icon: 'truck', label: 'Purchases', key: 'purchases', workerAllowed: false },
    { href: 'expenses.html', icon: 'wallet', label: 'Expenses', key: 'expenses', workerAllowed: true },
    { href: 'damages.html', icon: 'alert', label: 'Damages', key: 'damages', workerAllowed: true },
    { href: 'cash-audit.html', icon: 'calculator', label: 'Cash Audit', key: 'cash-audit', workerAllowed: true },
    { href: 'reports.html', icon: 'file-text', label: 'Reports', key: 'reports', workerAllowed: false },
    { href: 'audit-log.html', icon: 'clipboard', label: 'Activity Log', key: 'audit-log', workerAllowed: false },
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
/* ---------- Light / dark theme (saved per browser, applied on every admin page) ---------- */
function adminGetTheme() { try { return localStorage.getItem('inno_theme') === 'dark' ? 'dark' : 'light'; } catch (e) { return 'light'; } }
function adminApplyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('inno_theme', t); } catch (e) {}
    const b = document.getElementById('themeToggle');
    if (b) {
        b.innerHTML = adminIcon(t === 'dark' ? 'sun' : 'moon', 17);
        b.title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        b.setAttribute('aria-label', b.title);
    }
}
document.documentElement.setAttribute('data-theme', adminGetTheme());

/* ---------- Notification bell (top bar) ----------
   Seen / cleared notifications stay hidden (per browser). The key includes today's
   date, so a still-open problem comes back the next day, and a changed message
   (e.g. a different overdue amount) shows up again straight away. */
let _adminNotes = [];
function _noteKey(i) { return adminToday() + '|' + i.text.replace(/<[^>]*>/g, ''); }
function _noteSeen() { try { return JSON.parse(localStorage.getItem('inno_notes_seen') || '[]'); } catch (e) { return []; } }
function _noteSave(list) { try { localStorage.setItem('inno_notes_seen', JSON.stringify(list.filter(k => k.startsWith(adminToday() + '|')).slice(-100))); } catch (e) {} }
function adminDismissNotifications(items) { _noteSave(_noteSeen().concat(items.map(_noteKey))); adminRenderNotifications(); }
function adminSetNotifications(items) { _adminNotes = items; adminRenderNotifications(); }
function adminRenderNotifications() {
    const list = document.getElementById('bellList'), badge = document.getElementById('bellBadge'), clear = document.getElementById('bellClear');
    if (!list || !badge) return;
    const seen = _noteSeen();
    const items = _adminNotes.filter(i => !seen.includes(_noteKey(i)));
    const attention = items.filter(i => i.cls !== 'ok').length;
    badge.textContent = attention; badge.classList.toggle('v3-hidden', attention === 0);
    if (clear) clear.classList.toggle('v3-hidden', items.length === 0);
    list.innerHTML = items.length
        ? items.map((i, n) => `<div class="bell-row"><a class="bell-item ${i.cls}" href="${i.href}" data-n="${n}">${adminIcon(i.icon, 17)}<div>${i.text}</div></a><button type="button" class="bell-x" data-n="${n}" title="Mark as seen" aria-label="Mark as seen">&times;</button></div>`).join('')
        : '<div class="bell-empty">You\'re all caught up.</div>';
    list.querySelectorAll('.bell-x').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); adminDismissNotifications([items[+b.dataset.n]]); }));
    list.querySelectorAll('.bell-item').forEach(a => a.addEventListener('click', () => adminDismissNotifications([items[+a.dataset.n]])));
}

function renderAdminShell(activeKey, pageTitle, admin, options = {}) {
    const sidebarWrap = document.getElementById('adminSidebarWrap');
    const topbar = document.getElementById('adminTopbar');
    const visibleItems = NAV_ITEMS.filter(item => admin.role !== 'worker' || item.workerAllowed);
    renderPageHero(activeKey, pageTitle, admin);

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

        // Keep the menu where it was: restore its scroll position, and remember it
        // when a link is clicked, so choosing a page doesn't jump back to the top.
        const navEl = sidebarWrap.querySelector('.admin-nav');
        let saved = 0, hasSaved = false; try { const v = sessionStorage.getItem('inno_nav_scroll'); hasSaved = v !== null; saved = parseInt(v || '0', 10) || 0; } catch (e) {}
        navEl.scrollTop = saved;
        const remember = () => { try { sessionStorage.setItem('inno_nav_scroll', String(navEl.scrollTop)); } catch (e) {} };
        navEl.addEventListener('scroll', remember, { passive: true });
        navEl.querySelectorAll('a').forEach(a => a.addEventListener('click', remember));
        window.addEventListener('pagehide', remember);
        // First visit / short scroll memory: make sure the active page is visible, without moving the menu otherwise.
        const act = navEl.querySelector('a.active');
        if (act && !hasSaved && (act.offsetTop < navEl.scrollTop || act.offsetTop + act.offsetHeight > navEl.scrollTop + navEl.clientHeight)) {
            navEl.scrollTop = act.offsetTop - navEl.clientHeight / 2;
        }
    }

    if (topbar) {
        topbar.innerHTML = `
        <div style="display:flex;align-items:center;gap:14px;">
          <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu"><span></span><span></span><span></span></button>
          <div><h1>${pageTitle}</h1>${options.subtitle ? `<div class="topbar-sub">${options.subtitle}</div>` : ''}</div>
        </div>
        <div class="topbar-actions">
        ${options.topbarTools ? `<button type="button" class="topbar-icon" id="themeToggle"></button>
        <div class="bell-wrap"><button type="button" class="topbar-icon" id="bellBtn" aria-label="Notifications" title="Notifications">${adminIcon('bell', 17)}<span class="bell-badge v3-hidden" id="bellBadge">0</span></button>
          <div class="bell-menu" id="bellMenu"><div class="bell-title"><span>Notifications</span><button type="button" class="bell-clear v3-hidden" id="bellClear">Mark all as seen</button></div><div id="bellList"><div class="bell-empty">Loading…</div></div></div></div>` : ''}
        ${options.logoutButton ? `<button type="button" class="topbar-logout" id="topbarLogout" aria-label="Log out">${adminIcon('logout', 16)}<span class="tl-text">Log out</span></button>` : ''}
        </div>`;

        if (options.topbarTools) {
            adminApplyTheme(adminGetTheme());
            document.getElementById('themeToggle').addEventListener('click', () => adminApplyTheme(adminGetTheme() === 'dark' ? 'light' : 'dark'));
            document.getElementById('bellClear').addEventListener('click', e => { e.stopPropagation(); adminDismissNotifications(_adminNotes); });
            const bellMenu = document.getElementById('bellMenu');
            document.getElementById('bellBtn').addEventListener('click', e => { e.stopPropagation(); bellMenu.classList.toggle('open'); });
            document.addEventListener('click', e => { if (!bellMenu.contains(e.target)) bellMenu.classList.remove('open'); });
        }

        const topLogout = document.getElementById('topbarLogout');
        if (topLogout) topLogout.addEventListener('click', doLogout);

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


/* ==========================================================================
   Animated page hero — injected at the top of every admin page (the
   dashboard has its own bigger one). Text, icon and quick links are
   chosen per page from PAGE_HERO below.
   ========================================================================== */
const PAGE_HERO = {
    customers: { eyebrow: 'People',       icon: 'users',       sub: 'See who owes what, who pays on time, and manage credit limits.',
                 actions: [{ href: 'debts.html', icon: 'credit-card', label: 'Debts / Credit', primary: true }, { href: 'sales.html', icon: 'cart', label: 'Record a Sale' }] },
    'service-purchases': { eyebrow: 'Service supplies', icon: 'layers', sub: 'Paper, toner, ink and other materials used to deliver services.',
                 actions: [{ href: 'services.html', icon: 'wrench', label: 'Services', primary: true }, { href: 'sales.html', icon: 'cart', label: 'Record a Sale' }] },
    'cash-audit': { eyebrow: 'Money check', icon: 'calculator', sub: 'Count the drawer, compare with the system and bank the cash.',
                 actions: [{ href: 'sales.html', icon: 'cart', label: 'Record a Sale', primary: true }, { href: 'expenses.html', icon: 'wallet', label: 'Expenses' }] },
    'audit-log': { eyebrow: 'Accountability', icon: 'clipboard', sub: 'A trail of who did what in the system, and when.',
                 actions: [{ href: 'cash-audit.html', icon: 'calculator', label: 'Cash Audit', primary: true }, { href: 'dashboard.html', icon: 'bar-chart', label: 'Dashboard' }] },
    stock:     { eyebrow: 'Inventory',     icon: 'package',     sub: 'Track products, prices and stock levels.',
                 actions: [{ href: 'sales.html', icon: 'cart', label: 'Record a Sale', primary: true }, { href: 'purchases.html', icon: 'truck', label: 'New Purchase', adminOnly: true }] },
    services:  { eyebrow: 'Catalogue',     icon: 'wrench',      sub: 'Manage the services you offer and their prices.',
                 actions: [{ href: 'sales.html', icon: 'cart', label: 'Record a Sale', primary: true }, { href: 'stock.html', icon: 'package', label: 'View Stock' }] },
    sales:     { eyebrow: 'Point of sale', icon: 'cart',        sub: 'Record sales and review everything sold.',
                 actions: [{ href: 'debts.html', icon: 'credit-card', label: 'Debts / Credit', primary: true }, { href: 'stock.html', icon: 'package', label: 'View Stock' }] },
    debts:     { eyebrow: 'Credit',        icon: 'credit-card', sub: 'Follow up customer credit and record repayments.',
                 actions: [{ href: 'sales.html', icon: 'cart', label: 'Record a Sale', primary: true }, { href: 'reports.html', icon: 'file-text', label: 'Reports', adminOnly: true }] },
    expenses:  { eyebrow: 'Spending',      icon: 'wallet',      sub: 'Record and review the shop’s expenses.',
                 actions: [{ href: 'dashboard.html', icon: 'bar-chart', label: 'Dashboard', primary: true }, { href: 'reports.html', icon: 'file-text', label: 'Reports', adminOnly: true }] },
    damages:   { eyebrow: 'Losses',        icon: 'alert',       sub: 'Log expired or damaged items to keep stock accurate.',
                 actions: [{ href: 'stock.html', icon: 'package', label: 'View Stock', primary: true }, { href: 'dashboard.html', icon: 'bar-chart', label: 'Dashboard' }] },
    purchases: { eyebrow: 'Supply',        icon: 'truck',       sub: 'Record stock bought from suppliers.',
                 actions: [{ href: 'stock.html', icon: 'package', label: 'View Stock', primary: true }, { href: 'expenses.html', icon: 'wallet', label: 'Expenses' }] },
    reports:   { eyebrow: 'Insights',      icon: 'file-text',   sub: 'See how the business is performing over time.',
                 actions: [{ href: 'dashboard.html', icon: 'bar-chart', label: 'Dashboard', primary: true }, { href: 'expenses.html', icon: 'wallet', label: 'Expenses' }] },
    admins:    { eyebrow: 'Team',          icon: 'user',        sub: 'Manage the admins and workers who use this system.',
                 actions: [{ href: 'settings.html', icon: 'gear', label: 'Settings', primary: true }, { href: 'dashboard.html', icon: 'bar-chart', label: 'Dashboard' }] },
    settings:  { eyebrow: 'Configuration', icon: 'gear',        sub: 'Adjust system preferences and your account.',
                 actions: [{ href: 'dashboard.html', icon: 'bar-chart', label: 'Dashboard', primary: true }, { href: 'admins.html', icon: 'user', label: 'Admins & Workers', adminOnly: true }] },
};

let pageHeroClock = null;
function renderPageHero(activeKey, pageTitle, admin) {
    const meta = PAGE_HERO[activeKey];
    const content = document.querySelector('.admin-content');
    if (!meta || !content || document.getElementById('pageHero')) return;
    const isWorker = admin && admin.role === 'worker';
    const actions = meta.actions.filter(a => !(a.adminOnly && isWorker));
    const iconPaths = ADMIN_ICON_PATHS[meta.icon] || '';
    const hero = document.createElement('section');
    hero.className = 'dash-hero compact';
    hero.id = 'pageHero';
    hero.innerHTML = `
      <div class="hero-float" aria-hidden="true">
        <svg class="hero-ico" viewBox="0 0 24 24">${iconPaths}</svg>
        <svg style="top:14%;right:36%;width:40px;height:40px;animation-delay:-1s" viewBox="0 0 24 24">${ADMIN_ICON_PATHS.edit}</svg>
        <svg style="bottom:10%;right:48%;width:34px;height:34px;animation-delay:-3s" viewBox="0 0 24 24">${ADMIN_ICON_PATHS['file-text']}</svg>
      </div>
      <div class="hero-left">
        <div class="hero-eyebrow"><span id="phGreeting"></span> · ${meta.eyebrow}</div>
        <div class="hero-name">${adminEscapeHtml(pageTitle)}</div>
        <p class="hero-sub">${meta.sub}</p>
        <div class="hero-actions">
          ${actions.map(a => `<a href="${a.href}" class="hero-btn ${a.primary ? 'primary' : ''}">${adminIcon(a.icon, 15)} ${a.label}</a>`).join('')}
        </div>
      </div>
      <div class="hero-date">
        <div class="hd-day" id="phDay"></div>
        <div class="hd-num" id="phNum"></div>
        <div class="hd-month" id="phMonth"></div>
        <div class="hd-time"><span id="phTime"></span><small id="phSec"></small></div>
      </div>`;
    content.insertBefore(hero, content.firstChild);

    // rising gold dots
    const box = hero.querySelector('.hero-float');
    for (let i = 0; i < 12; i++) {
        const dot = document.createElement('span');
        const size = 4 + Math.random() * 7;
        dot.className = 'dot';
        dot.style.cssText = `left:${Math.random() * 100}%;width:${size}px;height:${size}px;animation-duration:${6 + Math.random() * 7}s;animation-delay:${-Math.random() * 10}s;`;
        box.appendChild(dot);
    }

    const tick = () => {
        const d = new Date(), h = d.getHours();
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        set('phGreeting', h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening');
        set('phDay', d.toLocaleDateString('en-GB', { weekday: 'long' }));
        set('phNum', d.getDate());
        set('phMonth', d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }));
        set('phTime', String(h).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'));
        set('phSec', String(d.getSeconds()).padStart(2, '0'));
    };
    tick();
    if (pageHeroClock) clearInterval(pageHeroClock);
    pageHeroClock = setInterval(tick, 1000);
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

/* ==========================================================================
   Count-up animation for numbers in stat cards (all pages).
   Watches .stat-value / .dsc-value; when a number appears or changes it
   counts up from 0. The final text is always restored exactly as written.
   ========================================================================== */
(function () {
    const SELECTOR = '.stat-value, .dsc-value';
    const NUM_RE = /(-?\d[\d,]*(?:\.\d+)?)/;
    const lastWritten = new WeakMap();
    const finals = new WeakMap();
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function animate(el) {
        const text = el.textContent;
        if (lastWritten.get(el) === text) return;           // our own write
        const m = text.match(NUM_RE);
        if (!m || reduce || el.children.length) { lastWritten.set(el, text); return; }
        const raw = m[1];
        const target = parseFloat(raw.replace(/,/g, ''));
        if (!isFinite(target) || target === 0) { lastWritten.set(el, text); return; }
        const decimals = (raw.split('.')[1] || '').length;
        const useCommas = raw.indexOf(',') !== -1 || Math.abs(target) >= 1000;
        const pre = text.slice(0, m.index), post = text.slice(m.index + raw.length);
        const fmt = v => pre + (useCommas
            ? v.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
            : v.toFixed(decimals)) + post;
        const token = {}; finals.set(el, token);
        const start = performance.now(), dur = 900;
        function write(t) { lastWritten.set(el, t); el.textContent = t; }
        (function step(now) {
            if (finals.get(el) !== token) return;           // superseded by newer value
            const p = Math.min((now - start) / dur, 1);
            const eased = 1 - Math.pow(1 - p, 3);
            write(p < 1 ? fmt(target * eased) : text);
            if (p < 1) requestAnimationFrame(step);
        })(start);
    }

    function scan(node) {
        if (node.nodeType !== 1) node = node.parentElement;
        if (!node) return;
        const own = node.closest && node.closest(SELECTOR);
        if (own) { animate(own); return; }
        if (node.querySelectorAll) node.querySelectorAll(SELECTOR).forEach(animate);
    }

    function init() {
        scan(document.body);
        new MutationObserver(muts => muts.forEach(m => {
            if (m.type === 'characterData') scan(m.target);
            else m.addedNodes.forEach(scan);
        })).observe(document.body, { childList: true, subtree: true, characterData: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

/* ---------- v3 shared helpers ---------- */
/** Today's date (YYYY-MM-DD) in the browser's LOCAL time — not UTC. */
function adminToday(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function adminDebounce(fn, ms = 300) {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}
/** Count-up animation for numbers inside a stat card. */
function adminCountUp(el, to, { duration = 700, money = true } = {}) {
    if (!el) return;
    const from = 0, start = performance.now();
    const fmt = v => money ? adminFormatMoney(v) : String(Math.round(v));
    const step = now => {
        const p = Math.min((now - start) / duration, 1);
        el.textContent = fmt(from + (to - from) * (1 - Math.pow(1 - p, 3)));
        if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
}
/** Open / close a .modal-backdrop by id. */
function adminOpenModal(id) { const m = document.getElementById(id); if (m) m.classList.add('open'); }
function adminCloseModal(id) { const m = document.getElementById(id); if (m) m.classList.remove('open'); }
function adminIsWorker(admin) { return admin && admin.role === 'worker'; }
