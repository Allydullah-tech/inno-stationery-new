/* Admin/worker account management */
let currentAdminInfo = null;

(async () => {
    const admin = await guardAdminPage();
    if (!admin) return;
    if (blockWorkers(admin)) return;
    currentAdminInfo = admin;
    renderAdminShell('admins', 'Admins & Workers', admin);

    addPasswordToggle(document.getElementById('na_password'));
    addPasswordToggle(document.getElementById('currentPw'));
    addPasswordToggle(document.getElementById('newPw'));

    loadAdmins();

    document.getElementById('newAdminBtn').addEventListener('click', () => document.getElementById('adminModalBackdrop').classList.add('open'));
    document.getElementById('adminModalClose').addEventListener('click', () => document.getElementById('adminModalBackdrop').classList.remove('open'));
    document.getElementById('adminModalBackdrop').addEventListener('click', (e) => { if (e.target.id === 'adminModalBackdrop') e.target.classList.remove('open'); });
    document.getElementById('newAdminForm').addEventListener('submit', createAdmin);
    document.getElementById('changePasswordForm').addEventListener('submit', changeMyPassword);
})();

async function loadAdmins() {
    const tbody = document.getElementById('adminsTbody');
    const res = await adminApi('/admin_manage.php');
    const admins = res.success ? res.admins : [];

    if (!admins.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:30px;">No accounts found.</td></tr>`;
        return;
    }

    tbody.innerHTML = admins.map(a => `
      <tr>
        <td>${adminEscapeHtml(a.full_name)}</td>
        <td class="mono">${adminEscapeHtml(a.username)}</td>
        <td>${adminEscapeHtml(a.email)}</td>
        <td style="text-transform:capitalize;">${a.role.replace('_',' ')}</td>
        <td><span class="status-pill status-${a.is_active ? 'active' : 'inactive'}">${a.is_active ? 'Active' : 'Inactive'}</span></td>
        <td style="font-size:12px;color:var(--ink-soft);">${new Date(a.created_at).toLocaleDateString()}</td>
        <td>
          ${currentAdminInfo.role === 'super_admin' && a.id !== currentAdminInfo.id
            ? `<button class="icon-btn ${a.is_active ? 'danger' : ''}" onclick="toggleAdminActive(${a.id}, ${a.is_active ? 0 : 1})">${a.is_active ? 'Deactivate' : 'Activate'}</button>`
            : ''}
        </td>
      </tr>
    `).join('');
}

async function createAdmin(e) {
    e.preventDefault();
    const alertBox = document.getElementById('newAdminAlert');
    alertBox.innerHTML = '';

    const sq1 = document.getElementById('na_sq1').value;
    const sq2 = document.getElementById('na_sq2').value;
    if (sq1 && sq1 === sq2) {
        alertBox.innerHTML = `<div class="alert alert-error">Please choose two different security questions.</div>`;
        return;
    }

    const payload = {
        full_name: document.getElementById('na_fullname').value.trim(),
        username: document.getElementById('na_username').value.trim(),
        email: document.getElementById('na_email').value.trim(),
        password: document.getElementById('na_password').value,
        role: document.getElementById('na_role').value,
        security_question_1: sq1,
        security_answer_1: document.getElementById('na_sa1').value.trim(),
        security_question_2: sq2,
        security_answer_2: document.getElementById('na_sa2').value.trim(),
    };

    const btn = document.getElementById('newAdminSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Creating…';

    const res = await adminApi('/admin_manage.php', { method: 'POST', body: payload });

    btn.disabled = false;
    btn.textContent = 'Create Account';

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }

    adminToast('Account created.', 'success');
    document.getElementById('adminModalBackdrop').classList.remove('open');
    document.getElementById('newAdminForm').reset();
    loadAdmins();
}

async function toggleAdminActive(id, isActive) {
    if (!confirm(isActive ? 'Reactivate this account?' : 'Deactivate this account? They will no longer be able to log in.')) return;
    const res = await adminApi('/admin_manage.php', { method: 'PUT', body: { id, is_active: isActive } });
    if (res.success) { adminToast('Account status updated.', 'success'); loadAdmins(); }
    else adminToast(res.message || 'Could not update.', 'error');
}

async function changeMyPassword(e) {
    e.preventDefault();
    const alertBox = document.getElementById('pwAlert');
    alertBox.innerHTML = '';

    const res = await adminApi('/admin_auth.php?action=change_password', {
        method: 'POST',
        body: {
            current_password: document.getElementById('currentPw').value,
            new_password: document.getElementById('newPw').value,
        },
    });

    if (!res.success) {
        alertBox.innerHTML = `<div class="alert alert-error">${adminEscapeHtml(res.message)}</div>`;
        return;
    }
    alertBox.innerHTML = `<div class="alert alert-success">Password changed successfully.</div>`;
    document.getElementById('changePasswordForm').reset();
}
