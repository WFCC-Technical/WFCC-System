<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$currentUser = $_SESSION['user'];
$today = wfcc_today();
$message = '';
$messageType = 'info';

ensure_attendance_table();
$pdo = wfcc_db();

// Handle clock in / clock out (only ever affects the logged-in user's own row).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'clock_in') {
                $stmt = $pdo->prepare(
                    'INSERT INTO attendance (user_id, log_date, time_in)
                     VALUES (:uid, :date, NOW())
                     ON CONFLICT (user_id, log_date)
                     DO UPDATE SET time_in = COALESCE(attendance.time_in, EXCLUDED.time_in)'
                );
                $stmt->execute(['uid' => $currentUser['id'], 'date' => $today]);
                $message = 'Clocked in.';
            } elseif ($action === 'clock_out') {
                $stmt = $pdo->prepare(
                    'UPDATE attendance SET time_out = NOW()
                     WHERE user_id = :uid AND log_date = :date AND time_in IS NOT NULL AND time_out IS NULL'
                );
                $stmt->execute(['uid' => $currentUser['id'], 'date' => $today]);
                $message = $stmt->rowCount() ? 'Clocked out.' : 'You need to clock in first, or already clocked out today.';
            }
        } catch (Throwable $e) {
            $message = 'Action failed: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Build the tab list: every distinct date that has activity, plus today
// even if nobody has clocked in yet — so today's tab is always present.
$dateRows = $pdo->query('SELECT DISTINCT log_date FROM attendance ORDER BY log_date DESC')->fetchAll();
$dates = array_map(fn($r) => $r['log_date'], $dateRows);
if (!in_array($today, $dates, true)) {
    array_unshift($dates, $today);
}

$activeDate = $_GET['date'] ?? $today;
if (!in_array($activeDate, $dates, true)) {
    $activeDate = $today;
}
$isToday = $activeDate === $today;

// Every employee's status for the active date (LEFT JOIN so absentees still show up).
$stmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.email, a.time_in, a.time_out
     FROM users u
     LEFT JOIN attendance a ON a.user_id = u.id AND a.log_date = :date
     ORDER BY u.full_name'
);
$stmt->execute(['date' => $activeDate]);
$rows = $stmt->fetchAll();

$myRow = null;
foreach ($rows as $r) {
    if ((int)$r['id'] === (int)$currentUser['id']) {
        $myRow = $r;
        break;
    }
}

function fmt_time(?string $ts): string
{
    return $ts ? (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Manila'))->format('h:i A') : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance — WFCC</title>
    <style>
        :root { --blue:#0b3d63; --gray:#f2f4f6; --text:#1f2a33; }
        body { margin:0; font-family:'Segoe UI',Roboto,Arial,sans-serif; background:var(--gray); }
        .wrap { max-width:820px; margin:40px auto; padding:0 20px; }
        .header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:18px; }
        .header h2 { margin:0 0 4px; color:var(--blue); }
        .header a { color:var(--blue); font-weight:600; text-decoration:none; font-size:0.9rem; margin-left:14px; }
        .clock { font-size:0.85rem; color:#6b7280; }
        .tabs { display:flex; gap:6px; overflow-x:auto; margin-bottom:16px; padding-bottom:4px; }
        .tab { flex:0 0 auto; padding:8px 14px; border-radius:8px 8px 0 0; background:#e5e7eb; color:#4b5563; text-decoration:none; font-size:0.85rem; font-weight:600; white-space:nowrap; }
        .tab-active { background:#fff; color:var(--blue); box-shadow:0 -2px 8px rgba(0,0,0,0.06); }
        .tab-today::after { content:" •"; color:#1e7e34; }
        .card { background:#fff; border-radius:0 12px 12px 12px; padding:24px; box-shadow:0 4px 16px rgba(0,0,0,0.06); }
        .alert { padding:10px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:14px; }
        .alert-error { background:#fdecea; color:#b3261e; border:1px solid #f5c2c0; }
        .alert-info { background:#eaf0fb; color:#2952a3; border:1px solid #c9d8f5; }
        table { width:100%; border-collapse:collapse; font-size:0.9rem; }
        th { text-align:left; font-size:0.75rem; text-transform:uppercase; color:#6b7280; padding:8px 6px; border-bottom:1px solid #e5e7eb; }
        td { padding:10px 6px; border-bottom:1px solid #f0f0f0; }
        .me { background:#f5f9ff; }
        .badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.78rem; font-weight:600; }
        .badge-in { background:#e6f4ea; color:#1e7e34; }
        .badge-out { background:#eaf0fb; color:#2952a3; }
        .badge-none { background:#eee; color:#666; }
        .actions button { padding:6px 12px; border-radius:6px; font-weight:600; font-size:0.8rem; cursor:pointer; border:none; }
        .btn-in { background:var(--blue); color:#fff; }
        .btn-out { background:#fff; color:var(--blue); border:1.5px solid var(--blue) !important; }
        button:disabled { opacity:0.4; cursor:not-allowed; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div>
                <h2>Attendance</h2>
                <p class="clock" id="liveClock"></p>
            </div>
            <div>
                <a href="home.php">Home</a>
                <a href="logout.php">Log out</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType === 'error' ? 'error' : 'info' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <?php foreach ($dates as $d): ?>
                <a href="?date=<?= htmlspecialchars($d) ?>"
                   class="tab <?= $d === $activeDate ? 'tab-active' : '' ?> <?= $d === $today ? 'tab-today' : '' ?>">
                    <?= date('M j', strtotime($d)) ?><?= $d === $today ? ' (Today)' : '' ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <?php if ($isToday): ?>
                <form method="POST" style="margin-bottom:18px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <span style="font-size:0.9rem; margin-right:10px;">
                        Your status: <strong><?= fmt_time($myRow['time_in'] ?? null) ?> – <?= fmt_time($myRow['time_out'] ?? null) ?></strong>
                    </span>
                    <button type="submit" name="action" value="clock_in" class="btn-in"
                        <?= ($myRow && $myRow['time_in']) ? 'disabled' : '' ?>>Clock In</button>
                    <button type="submit" name="action" value="clock_out" class="btn-out"
                        <?= (!$myRow || !$myRow['time_in'] || $myRow['time_out']) ? 'disabled' : '' ?>>Clock Out</button>
                </form>
            <?php else: ?>
                <p class="clock" style="margin-bottom:14px;">Viewing a past date — read only.</p>
            <?php endif; ?>

            <table>
                <thead>
                    <tr><th>Name</th><th>Time In</th><th>Time Out</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $isMe = (int)$r['id'] === (int)$currentUser['id'];
                            $badge = 'badge-none'; $label = 'Not clocked in';
                            if ($r['time_in'] && $r['time_out']) { $badge = 'badge-out'; $label = 'Done'; }
                            elseif ($r['time_in']) { $badge = 'badge-in'; $label = 'Present'; }
                        ?>
                        <tr class="<?= $isMe ? 'me' : '' ?>">
                            <td><?= htmlspecialchars($r['full_name']) ?><?= $isMe ? ' (you)' : '' ?></td>
                            <td><?= fmt_time($r['time_in']) ?></td>
                            <td><?= fmt_time($r['time_out']) ?></td>
                            <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function tick() {
            var now = new Date().toLocaleString('en-PH', {
                timeZone: 'Asia/Manila',
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
            document.getElementById('liveClock').textContent = now + ' (Asia/Manila)';
        }
        tick();
        setInterval(tick, 1000);
    </script>
</body>
</html>
