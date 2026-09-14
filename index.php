<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user'])) {
    header('Location: home.php');
    exit;
}

$tab = ($_GET['tab'] ?? 'login') === 'register' ? 'register' : 'login';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'register') {
            $tab = 'register';
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $confirm  = (string)($_POST['confirm_password'] ?? '');

            if ($fullName === '' || $email === '' || $password === '') {
                $error = 'All fields are required.';
            } elseif ($password !== $confirm) {
                $error = 'Passwords do not match.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                try {
                    ensure_users_table();
                    $pdo = wfcc_db();
                    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email');
                    $check->execute(['email' => $email]);

                    if ($check->fetch()) {
                        $error = 'An account with that email already exists.';
                    } else {
                        // Bootstraps the system: the very first account ever
                        // created becomes Admin (so there's someone able to
                        // assign roles from the start); everyone after that
                        // starts as Applicant until an Admin promotes them.
                        $isFirstUser = (int)$pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'] === 0;
                        $role = $isFirstUser ? 'Admin' : 'Applicant';

                        $stmt = $pdo->prepare(
                            'INSERT INTO users (full_name, email, password_hash, role) VALUES (:name, :email, :hash, :role)'
                        );
                        $stmt->execute([
                            'name'  => $fullName,
                            'email' => $email,
                            'hash'  => password_hash($password, PASSWORD_BCRYPT),
                            'role'  => $role,
                        ]);
                        $tab = 'login';
                        $success = $isFirstUser
                            ? 'Account created as Admin (first account on the system). You can now log in below.'
                            : 'Account created! You can now log in below.';
                    }
                } catch (Throwable $e) {
                    $error = 'Could not reach the database: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'login') {
            $tab = 'login';
            $email    = trim($_POST['login_email'] ?? '');
            $password = (string)($_POST['login_password'] ?? '');

            if ($email === '' || $password === '') {
                $error = 'Please enter your email and password.';
            } else {
                try {
                    ensure_users_table();
                    $pdo = wfcc_db();
                    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
                    $stmt->execute(['email' => $email]);
                    $user = $stmt->fetch();

                    if (!$user || !password_verify($password, $user['password_hash'])) {
                        $error = 'Invalid email or password.';
                    } else {
                        session_regenerate_id(true);
                        unset($user['password_hash']);
                        $_SESSION['user'] = $user;
                        header('Location: home.php');
                        exit;
                    }
                } catch (Throwable $e) {
                    $error = 'Could not reach the database: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WFCC Attendance System</title>
    <style>
        :root { --blue:#0b3d63; --blue-dark:#082c48; --gray:#f2f4f6; --text:#1f2a33; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:'Segoe UI',Roboto,Arial,sans-serif; background:linear-gradient(135deg,var(--blue),var(--blue-dark)); min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .card { background:#fff; width:100%; max-width:380px; border-radius:12px; padding:36px 32px; box-shadow:0 20px 40px rgba(0,0,0,0.25); margin:20px; }
        .brand { text-align:center; margin-bottom:20px; }
        .brand h1 { font-size:1.2rem; color:var(--blue); margin:0 0 4px; }
        .brand p { font-size:0.85rem; color:#6b7280; margin:0; }
        .tabs { display:flex; border-bottom:1px solid #e5e7eb; margin-bottom:18px; }
        .tab { flex:1; text-align:center; padding:10px 0; text-decoration:none; font-size:0.9rem; font-weight:600; color:#9ca3af; border-bottom:2px solid transparent; margin-bottom:-1px; }
        .tab-active { color:var(--blue); border-bottom-color:var(--blue); }
        label { display:block; font-size:0.85rem; font-weight:600; color:var(--text); margin:14px 0 6px; }
        input { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.95rem; }
        input:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(11,61,99,0.12); }
        button { width:100%; margin-top:20px; padding:12px; background:var(--blue); color:#fff; border:none; border-radius:8px; font-size:1rem; font-weight:600; cursor:pointer; }
        button:hover { background:var(--blue-dark); }
        .alert { padding:10px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:6px; }
        .alert-error { background:#fdecea; color:#b3261e; border:1px solid #f5c2c0; }
        .alert-info { background:#eaf0fb; color:#2952a3; border:1px solid #c9d8f5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <h1>W.F. Construction Corp.</h1>
            <p>Attendance &amp; File Monitoring System</p>
        </div>

        <div class="tabs">
            <a href="?tab=login" class="tab <?= $tab === 'login' ? 'tab-active' : '' ?>">Log In</a>
            <a href="?tab=register" class="tab <?= $tab === 'register' ? 'tab-active' : '' ?>">Register</a>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <?php if ($tab === 'login'): ?>
            <form method="POST" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Email</label>
                <input type="email" name="login_email" required autofocus>
                <label>Password</label>
                <input type="password" name="login_password" required>
                <button type="submit">Log In</button>
            </form>
        <?php else: ?>
            <form method="POST" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="register">
                <label>Full Name</label>
                <input type="text" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                <label>Email</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <label>Password</label>
                <input type="password" name="password" required>
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required>
                <button type="submit">Create Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
