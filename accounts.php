<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$currentUser = $_SESSION['user'];

// NOTE: temporarily open to any logged-in user while existing accounts are
// still being assigned roles for the first time. Re-add the is_admin()
// gate here once every account has a proper role set.

ensure_users_table();
$pdo = wfcc_db();

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please try again.';
        $messageType = 'error';
    } else {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newRole  = (string)($_POST['role'] ?? '');

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

            // Keep the session's own role in sync if the admin edited themself.
            if ($targetId === (int)$currentUser['id']) {
                $_SESSION['user']['role'] = $newRole;
                $currentUser = $_SESSION['user'];
            }

            $message = 'Role updated.';
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
        .wrap { max-width:820px; margin:40px auto; padding:0 20px; }
        .header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:18px; }
        .header h2 { margin:0 0 4px; color:var(--blue); }
        .header a { color:var(--blue); font-weight:600; text-decoration:none; font-size:0.9rem; margin-left:14px; }
        .card { background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 16px rgba(0,0,0,0.06); }
        .alert { padding:10px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:14px; }
        .alert-error { background:#fdecea; color:#b3261e; border:1px solid #f5c2c0; }
        .alert-info { background:#eaf0fb; color:#2952a3; border:1px solid #c9d8f5; }
        table { width:100%; border-collapse:collapse; font-size:0.9rem; }
        th { text-align:left; font-size:0.75rem; text-transform:uppercase; color:#6b7280; padding:8px 6px; border-bottom:1px solid #e5e7eb; }
        td { padding:10px 6px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
        .me { background:#f5f9ff; }
        select.role-select { padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85rem; font-weight:600; color:var(--blue); background:#fff; }
        select.role-select:focus { outline:none; border-color:var(--blue); }
        .hint { font-size:0.75rem; color:#9ca3af; margin-top:2px; }
        noscript button { margin-left:6px; padding:5px 10px; border-radius:6px; border:1.5px solid var(--blue); background:#fff; color:var(--blue); font-weight:600; font-size:0.78rem; cursor:pointer; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div>
                <h2>Accounts</h2>
                <p class="hint">Assign or change each user's role.</p>
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
                    <tr><th>Name</th><th>Email</th><th>Role</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $isMe = (int)$r['id'] === (int)$currentUser['id']; ?>
                        <tr class="<?= $isMe ? 'me' : '' ?>">
                            <td><?= htmlspecialchars($r['full_name']) ?><?= $isMe ? ' (you)' : '' ?></td>
                            <td><?= htmlspecialchars($r['email']) ?></td>
                            <td>
                                <form method="POST" style="display:flex; align-items:center; gap:6px;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                                    <select name="role" class="role-select" onchange="this.form.submit()">
                                        <?php foreach (WFCC_ROLES as $roleOption): ?>
                                            <option value="<?= htmlspecialchars($roleOption) ?>" <?= $roleOption === $r['role'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($roleOption) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <noscript><button type="submit">Save</button></noscript>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
