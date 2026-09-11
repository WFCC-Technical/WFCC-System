<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$currentUser = $_SESSION['user'];
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $userId = (int)($_POST['user_id'] ?? 0);

        try {
            $pdo = wfcc_db();

            if ($action === 'update') {
                $fullName    = trim($_POST['full_name'] ?? '');
                $email       = trim($_POST['email'] ?? '');
                $newPassword = (string)($_POST['new_password'] ?? '');

                if ($fullName === '' || $email === '') {
                    $message = 'Name and email cannot be blank.';
                    $messageType = 'error';
                } else {
                    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
                    $check->execute(['email' => $email, 'id' => $userId]);

                    if ($check->fetch()) {
                        $message = 'That email is already used by another account.';
                        $messageType = 'error';
                    } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
                        $message = 'New password must be at least 8 characters.';
                        $messageType = 'error';
                    } else {
                        if ($newPassword !== '') {
                            $stmt = $pdo->prepare('UPDATE users SET full_name = :name, email = :email, password_hash = :hash WHERE id = :id');
                            $stmt->execute(['name' => $fullName, 'email' => $email, 'hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'id' => $userId]);
                        } else {
                            $stmt = $pdo->prepare('UPDATE users SET full_name = :name, email = :email WHERE id = :id');
                            $stmt->execute(['name' => $fullName, 'email' => $email, 'id' => $userId]);
                        }
                        $message = 'Account updated.';

                        if ($userId === (int)$currentUser['id']) {
                            $_SESSION['user']['full_name'] = $fullName;
                            $_SESSION['user']['email'] = $email;
                            $currentUser = $_SESSION['user'];
                        }
                    }
                }
            } elseif ($action === 'delete') {
                if ($userId === (int)$currentUser['id']) {
                    $message = 'You cannot delete your own account while logged in as it.';
                    $messageType = 'error';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $stmt->execute(['id' => $userId]);
                    $message = 'Account deleted.';
                }
            }
        } catch (Throwable $e) {
            $message = 'Action failed: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

try {
    ensure_users_table();
    $users = wfcc_db()->query('SELECT id, full_name, email, created_at FROM users ORDER BY created_at DESC')->fetchAll();
} catch (Throwable $e) {
    $users = [];
    $message = 'Could not load accounts: ' . $e->getMessage();
    $messageType = 'error';
}
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
        .wrap { max-width:900px; margin:50px auto; padding:0 20px; }
        .header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px; }
        .header h2 { margin:0 0 4px; color:var(--blue); }
        .header a { color:var(--blue); font-weight:600; text-decoration:none; font-size:0.9rem; margin-left:14px; }
        .card { background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 16px rgba(0,0,0,0.06); }
        .alert { padding:10px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:14px; }
        .alert-error { background:#fdecea; color:#b3261e; border:1px solid #f5c2c0; }
        .alert-info { background:#eaf0fb; color:#2952a3; border:1px solid #c9d8f5; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; margin-top:12px; }
        .row { border:1px solid #e5e7eb; border-radius:10px; padding:16px; background:#fafafa; }
        .row label { display:block; font-size:0.8rem; font-weight:600; margin:10px 0 4px; }
        .row input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:0.9rem; box-sizing:border-box; }
        .actions { display:flex; gap:8px; margin-top:14px; }
        .actions button { flex:1; padding:8px; border-radius:6px; font-weight:600; font-size:0.85rem; cursor:pointer; }
        .btn-save { background:var(--blue); color:#fff; border:none; }
        .btn-delete { background:#fff; color:#b3261e; border:1.5px solid #b3261e; }
        .muted { color:#6b7280; font-size:0.8rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <h2>Existing Accounts (<?= count($users) ?>)</h2>
            <div>
                <a href="home.php">Home</a>
                <a href="logout.php">Log out</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType === 'error' ? 'error' : 'info' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <?php if (empty($users)): ?>
                <p class="muted">No accounts yet.</p>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($users as $u): ?>
                        <form method="POST" class="row">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                            <label>Name</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($u['full_name']) ?>" required>

                            <label>Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required>

                            <label>New Password <span class="muted">(blank = keep current)</span></label>
                            <input type="password" name="new_password" placeholder="At least 8 characters">

                            <div class="actions">
                                <button type="submit" name="action" value="update" class="btn-save">Save</button>
                                <button type="submit" name="action" value="delete" class="btn-delete"
                                    onclick="return confirm('Delete this account? This cannot be undone.');">Delete</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
