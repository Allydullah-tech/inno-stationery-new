<?php
/**
 * INNO's STATIONERY — Internal System - Installer
 * ---------------------------------------------------------------
 * Run this ONCE from your browser (e.g. https://yourdomain.com/backend/install.php)
 * or from the command line (php install.php) to:
 *   1. Create all database tables (from database/schema.sql)
 *   2. Create the FIRST admin account manually (no public signup exists)
 *
 * After installation succeeds, DELETE this file or rename it,
 * otherwise anyone could re-run it and create a rogue admin.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/config/database.php';

$lockFile = __DIR__ . '/install.lock';
$alreadyInstalled = file_exists($lockFile);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $sq1 = trim($_POST['security_question_1'] ?? '');
    $sa1 = trim($_POST['security_answer_1'] ?? '');
    $sq2 = trim($_POST['security_question_2'] ?? '');
    $sa2 = trim($_POST['security_answer_2'] ?? '');

    if ($fullName === '' || $username === '' || $email === '' || $password === '') {
        $errors[] = 'Please fill in all required fields.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if ($sq1 === '' || $sa1 === '' || $sq2 === '' || $sa2 === '' || $sq1 === $sq2) {
        $errors[] = 'Please provide two DIFFERENT security questions with answers (used for password recovery).';
    }

    if (empty($errors)) {
        try {
            $pdo = get_db();

            // 1. Build schema
            $schemaSql = file_get_contents(__DIR__ . '/../database/schema.sql');
            if ($schemaSql === false) {
                throw new Exception('Could not read database/schema.sql. Make sure the database folder is present.');
            }
            // Split on semicolons at end of line, executing each statement.
            $statements = array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $schemaSql))));
            foreach ($statements as $stmt) {
                if ($stmt === '' || str_starts_with(ltrim($stmt), '--')) {
                    continue;
                }
                $pdo->exec($stmt);
            }

            // 2. Create first super admin
            $check = $pdo->prepare('SELECT COUNT(*) FROM admins');
            $check->execute();
            if ((int) $check->fetchColumn() > 0) {
                throw new Exception('An admin account already exists. Installation already completed.');
            }

            $insert = $pdo->prepare('INSERT INTO admins
                (full_name, username, email, password_hash, role, security_question_1, security_answer_1, security_question_2, security_answer_2)
                VALUES (?, ?, ?, ?, "super_admin", ?, ?, ?, ?)');
            $insert->execute([
                $fullName,
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $sq1,
                password_hash(strtolower($sa1), PASSWORD_DEFAULT),
                $sq2,
                password_hash(strtolower($sa2), PASSWORD_DEFAULT),
            ]);

            file_put_contents($lockFile, 'installed_at=' . date('c'));
            $success = true;
        } catch (Throwable $e) {
            $errors[] = 'Installation error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Install &mdash; INNO's STATIONERY — Internal System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    body{font-family:'Segoe UI',Arial,sans-serif;background:#f7f5f0;color:#1f2430;max-width:640px;margin:40px auto;padding:0 20px;}
    h1{color:#2b3a67;}
    .card{background:#fff;border:1px solid #e4dfd2;border-radius:10px;padding:28px;box-shadow:0 4px 14px rgba(31,36,48,.06);}
    label{display:block;margin-top:14px;font-weight:600;font-size:14px;}
    input,select{width:100%;padding:10px;margin-top:6px;border:1px solid #cfc9b8;border-radius:6px;font-size:14px;box-sizing:border-box;}
    button{margin-top:22px;background:#2b3a67;color:#fff;border:0;padding:12px 22px;border-radius:6px;font-size:15px;cursor:pointer;}
    button:hover{background:#1f2a4d;}
    .error{background:#fdeceb;border:1px solid #c1443c;color:#7a2620;padding:10px 14px;border-radius:6px;margin-top:12px;}
    .success{background:#eaf5ee;border:1px solid #4c7a5e;color:#274a37;padding:14px;border-radius:6px;}
    .hint{color:#6b6b6b;font-size:13px;margin-top:4px;}
    code{background:#f1eee3;padding:2px 6px;border-radius:4px;}
</style>
</head>
<body>
<h1>INNO's STATIONERY — Internal System &mdash; Installer</h1>

<?php if ($alreadyInstalled): ?>
    <div class="card">
        <p class="success">✔ The system is already installed. This installer is now locked for security.</p>
        <p>If you need to create another admin, log in and use the "Add Admin" feature in the dashboard, or
        an existing super admin can add one for you.</p>
        <p class="hint">To reinstall from scratch, delete <code>backend/install.lock</code> and drop the database tables (this destroys all data).</p>
        <p><a href="../frontend/admin/login.html">Go to Admin Login →</a></p>
    </div>
<?php elseif ($success): ?>
    <div class="card">
        <p class="success">✔ Installation complete! Your admin account has been created.</p>
        <p><strong>Important:</strong> delete or rename <code>backend/install.php</code> now, since it is locked but still best removed.</p>
        <p><a href="../frontend/admin/login.html">Go to Admin Login →</a></p>
    </div>
<?php else: ?>
    <div class="card">
        <p>This will set up your database tables and create your first (super admin) account. Run this only once.</p>
        <?php foreach ($errors as $err): ?>
            <div class="error"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
        <form method="POST">
            <label>Full name *</label>
            <input type="text" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">

            <label>Username *</label>
            <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

            <label>Email *</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

            <label>Password * (min 8 characters)</label>
            <input type="password" name="password" required minlength="8">

            <label>Confirm password *</label>
            <input type="password" name="confirm_password" required minlength="8">

            <hr style="margin-top:22px;border:none;border-top:1px solid #eee;">
            <p class="hint">Security questions are used to reset your password if you forget it. Choose two different questions.</p>

            <label>Security question 1 *</label>
            <select name="security_question_1" required>
                <option value="">-- choose --</option>
                <option>What is the name of your first pet?</option>
                <option>What city were you born in?</option>
                <option>What is your mother's maiden name?</option>
                <option>What was the name of your first school?</option>
                <option>What is your favourite teacher's name?</option>
            </select>
            <label>Answer 1 *</label>
            <input type="text" name="security_answer_1" required>

            <label>Security question 2 *</label>
            <select name="security_question_2" required>
                <option value="">-- choose --</option>
                <option>What is the name of your first pet?</option>
                <option>What city were you born in?</option>
                <option>What is your mother's maiden name?</option>
                <option>What was the name of your first school?</option>
                <option>What is your favourite teacher's name?</option>
            </select>
            <label>Answer 2 *</label>
            <input type="text" name="security_answer_2" required>

            <button type="submit">Install &amp; Create Admin</button>
        </form>
    </div>
<?php endif; ?>
</body>
</html>
