<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$currentUser = $_SESSION['user'];

if (!is_admin($currentUser)) {
    header('Location: home.php');
    exit;
}

ensure_users_table();
$pdo = wfcc_db();

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please try again.';
        $messageType = 'error';
    } else {
        $do       = (string)($_POST['do'] ?? '');
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($do === 'update_role') {
            $newRole = (string)($_POST['role'] ?? '');

            if (!in_array($newRole, WFCC_ROLES, true)) {
                $message = 'Not a valid role.';
                $messageType = 'error';
            } elseif ($targetId === (int)$currentUser['id'] && $newRole !== 'Admin') {
                // Guard rail: an Admin can't demote themselves and get locked
                // out of this page (there'd be no one left to fix it).
                $message = "You can't remove your own Admin role from here.";
                $messageType = 'error';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
                $stmt->execute(['role' => $newRole, 'id' => $targetId]);

                if ($targetId === (int)$currentUser['id']) {
                    $_SESSION['user']['role'] = $newRole;
                    $currentUser = $_SESSION['user'];
                }

                $message = 'Role updated.';
            }
        } elseif ($do === 'update_details') {
            $newEmail    = trim((string)($_POST['email'] ?? ''));
            $newPassword = (string)($_POST['password'] ?? '');

            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $message = 'Enter a valid email address.';
                $messageType = 'error';
            } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
                $message = 'New password must be at least 8 characters (or leave it blank to keep the current one).';
                $messageType = 'error';
            } else {
                $dupe = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id');
                $dupe->execute(['email' => $newEmail, 'id' => $targetId]);

                if ($dupe->fetch()) {
                    $message = 'Another account already uses that email.';
                    $messageType = 'error';
                } else {
                    if ($newPassword !== '') {
                        $stmt = $pdo->prepare('UPDATE users SET email = :email, password_hash = :hash WHERE id = :id');
                        $stmt->execute([
                            'email' => $newEmail,
                            'hash'  => password_hash($newPassword, PASSWORD_BCRYPT),
                            'id'    => $targetId,
                        ]);
                        $message = 'Email and password updated.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
                        $stmt->execute(['email' => $newEmail, 'id' => $targetId]);
                        $message = 'Email updated.';
                    }

                    if ($targetId === (int)$currentUser['id']) {
                        $_SESSION['user']['email'] = $newEmail;
                        $currentUser = $_SESSION['user'];
                    }
                }
            }
        } elseif ($do === 'delete') {
            if ($targetId === (int)$currentUser['id']) {
                // Guard rail: don't let an Admin delete the account they're
                // currently logged in as.
                $message = "You can't delete your own account while logged in as it.";
                $messageType = 'error';
            } else {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute(['id' => $targetId]);
                $message = 'Account deleted.';
            }
        } else {
            $message = 'Unknown action.';
            $messageType = 'error';
        }
    }
}

$rows = $pdo->query('SELECT id, full_name, email, role, created_at FROM users ORDER BY full_name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts — WFCC</title>
    <style>
        :root { --blue:#0b3d63; --gray:#f2f4f6; --text:#1f2a33; }
        body { margin:0; font-family:'Segoe UI',Roboto,Arial,sans-serif; background:var(--gray); }
        .wrap { max-width:960px; margin:40px auto; padding:0 20px; }
        .header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:18px; }
        .header h2 { margin:0 0 4px; color:var(--blue); }
        .header a { color:var(--blue); font-weight:600; text-decoration:none; font-size:0.9rem; margin-left:14px; }
        .card { background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 16px rgba(0,0,0,0.06); }
        .alert { padding:10px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:14px; }
        .alert-error { background:#fdecea; color:#b3261e; border:1px solid #f5c2c0; }
        .alert-info { background:#eaf0fb; color:#2952a3; border:1px solid #c9d8f5; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { text-align:left; font-size:0.72rem; text-transform:uppercase; color:#6b7280; padding:8px 6px; border-bottom:1px solid #e5e7eb; }
        td { padding:10px 6px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
        .me { background:#f5f9ff; }
        .row-form { display:flex; align-items:center; gap:6px; }
        select.role-select { padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.82rem; font-weight:600; color:var(--blue); background:#fff; }
        select.role-select:focus { outline:none; border-color:var(--blue); }
        input.field { padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.82rem; width:150px; }
        input.field:focus { outline:none; border-color:var(--blue); }
        .hint { font-size:0.75rem; color:#9ca3af; margin-top:2px; }
        .btn-save { padding:6px 10px; border-radius:6px; border:1.5px solid var(--blue); background:#fff; color:var(--blue); font-weight:600; font-size:0.78rem; cursor:pointer; }
        .btn-save:hover { background:var(--blue); color:#fff; }
        .btn-delete { padding:6px 10px; border-radius:6px; border:1.5px solid #b3261e; background:#fff; color:#b3261e; font-weight:600; font-size:0.78rem; cursor:pointer; }
        .btn-delete:hover { background:#b3261e; color:#fff; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div>
                <h2>Accounts</h2>
                <p class="hint">Manage roles, email/password, and account deletion.</p>
            </div>
            <div>
                <a href="home.php">Home</a>
                <a href="logout.php">Log out</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType === 'error' ? 'error' : 'info' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <table>
                <thead>
                    <tr><th>Name</th><th>Role</th><th>Email / Password</th><th>Delete</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $isMe = (int)$r['id'] === (int)$currentUser['id']; ?>
                        <tr class="<?= $isMe ? 'me' : '' ?>">
                            <td><?= htmlspecialchars($r['full_name']) ?><?= $isMe ? ' (you)' : '' ?></td>

                            <td>
                                <form method="POST" class="row-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="do" value="update_role">
                                    <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                                    <select name="role" class="role-select" onchange="this.form.submit()">
                                        <?php foreach (WFCC_ROLES as $roleOption): ?>
                                            <option value="<?= htmlspecialchars($roleOption) ?>" <?= $roleOption === $r['role'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($roleOption) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <noscript><button type="submit" class="btn-save">Save</button></noscript>
                                </form>
                            </td>

                            <td>
                                <form method="POST" class="row-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="do" value="update_details">
                                    <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                                    <input type="email" name="email" class="field" value="<?= htmlspecialchars($r['email']) ?>" required>
                                    <input type="password" name="password" class="field" placeholder="New password (optional)">
                                    <button type="submit" class="btn-save">Save</button>
                                </form>
                            </td>

                            <td>
                                <?php if (!$isMe): ?>
                                    <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($r['full_name'])) ?>\'s account? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="do" value="delete">
                                        <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn-delete">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="hint">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
