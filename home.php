<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home — WFCC</title>
    <style>
        :root { --blue:#0b3d63; --gray:#f2f4f6; --text:#1f2a33; }
        body { margin:0; font-family:'Segoe UI',Roboto,Arial,sans-serif; background:var(--gray); }
        .wrap { max-width:600px; margin:60px auto; padding:0 20px; }
        .header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px; }
        .header h2 { margin:0 0 4px; color:var(--blue); }
        .header p { margin:0; color:#6b7280; font-size:0.85rem; }
        .header a { color:var(--blue); font-weight:600; text-decoration:none; font-size:0.9rem; }
        .card { background:#fff; border-radius:12px; padding:28px; box-shadow:0 4px 16px rgba(0,0,0,0.06); text-align:center; }
        .btn { display:inline-block; margin-top:16px; padding:12px 20px; background:var(--blue); color:#fff; border-radius:8px; text-decoration:none; font-weight:600; }
        .btn:hover { background:#082c48; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div>
                <h2>Welcome, <?= htmlspecialchars($user['full_name']) ?></h2>
                <p><?= htmlspecialchars($user['email']) ?> · <?= htmlspecialchars($user['role'] ?? 'Applicant') ?></p>
            </div>
            <a href="logout.php">Log out</a>
        </div>

        <div class="card">
            <p>You're logged in. This will grow into the full attendance &amp; file monitoring dashboard.</p>
            <a href="attendance.php" class="btn">Go to Attendance</a>
            <a href="accounts.php" class="btn" style="background:#fff; color:var(--blue); border:1.5px solid var(--blue); margin-left:8px;">View Accounts</a>
        </div>
    </div>
</body>
</html>
