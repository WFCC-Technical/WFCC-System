<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$currentUser = $_SESSION['user'];

if (!can_view_projects($currentUser)) {
    header('Location: home.php');
    exit;
}

ensure_projects_table();
ensure_project_documents_table();
$pdo = wfcc_db();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $error = 'Project name is required.';
        } elseif (mb_strlen($name) > 150) {
            $error = 'Project name is too long (150 characters max).';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO projects (name, status, created_by) VALUES (:name, :status, :uid)'
            );
            $stmt->execute([
                'name' => $name,
                'status' => 'Ongoing',
                'uid' => $currentUser['id'],
            ]);
            $success = 'Project created.';
        }
    }
}

$projects = $pdo->query(
    "SELECT p.id, p.name, p.status, p.created_at, u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM project_documents d WHERE d.project_id = p.id) AS doc_count
     FROM projects p
     LEFT JOIN users u ON u.id = p.created_by
     ORDER BY p.created_at DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Projects — WFCC</title>
<style>
:root { --blue:#0b3d63; --gray:#f2f4f6; --text:#1f2a33; }
body { margin:0; font-family:'Segoe UI',Roboto,Arial,sans-serif; background:var(--gray); }
.wrap { max-width:960px; margin:40px auto; padding:0 20px; }
.header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:18px; }
.header h2 { margin:0 0 4px; color:var(--blue); }
.header a { color:var(--blue); font-weight:600; text-decoration:none; font-size:0.9rem; margin-left:14px; }
.alert { padding:10px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:14px; }
.alert-error { background:#fdecea; color:#b3261e; border:1px solid #f5c2c0; }
.alert-info { background:#eaf0fb; color:#2952a3; border:1px solid #c9d8f5; }
.new-project { margin-bottom:20px; }
.new-project summary { cursor:pointer; display:inline-block; padding:10px 16px; background:var(--blue); color:#fff; border-radius:8px; font-weight:600; font-size:0.9rem; list-style:none; }
.new-project summary::-webkit-details-marker { display:none; }
.new-project-form { background:#fff; border-radius:12px; padding:20px; margin-top:10px; box-shadow:0 4px 16px rgba(0,0,0,0.06); display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.new-project-form label { display:block; font-size:0.85rem; font-weight:600; color:var(--text); margin-bottom:6px; }
.new-project-form input { padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; width:280px; }
.new-project-form input:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(11,61,99,0.12); }
.new-project-form button { padding:10.5px 18px; background:var(--blue); color:#fff; border:none; border-radius:8px; font-weight:600; font-size:0.9rem; cursor:pointer; }
.new-project-form button:hover { background:#082c48; }
.grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:16px; }
.project-card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 4px 16px rgba(0,0,0,0.06); display:flex; flex-direction:column; gap:10px; }
.project-card h3 { margin:0; font-size:1rem; color:var(--text); word-break:break-word; }
.pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.75rem; font-weight:600; width:fit-content; }
.pill-ongoing { background:#eaf0fb; color:#2952a3; }
.pill-finished { background:#e6f4ea; color:#1e7e34; }
.meta { font-size:0.75rem; color:#9ca3af; }
.edit-btn { margin-top:auto; text-align:center; padding:8px 0; border-radius:8px; border:1.5px solid var(--blue); color:var(--blue); font-weight:600; font-size:0.82rem; text-decoration:none; }
.edit-btn:hover { background:var(--blue); color:#fff; }
.empty { color:#9ca3af; font-size:0.9rem; }
</style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div>
            <h2>Projects</h2>
            <p class="meta">Create a project tab and track its documents in one place.</p>
        </div>
        <div>
            <a href="home.php">Home</a>
            <a href="logout.php">Log out</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <details class="new-project"<?= $error ? ' open' : '' ?>>
        <summary>+ New Project</summary>
        <form method="POST" class="new-project-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div>
                <label>Project name</label>
                <input type="text" name="name" required maxlength="150"
                       placeholder="e.g. Site B — Phase 2"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
            <button type="submit">Create</button>
        </form>
    </details>

    <?php if (!$projects): ?>
        <p class="empty">No projects yet. Create the first one above.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($projects as $p): ?>
                <div class="project-card">
                    <h3><?= htmlspecialchars($p['name']) ?></h3>
                    <span class="pill <?= $p['status'] === 'Finished' ? 'pill-finished' : 'pill-ongoing' ?>">
                        <?= htmlspecialchars($p['status']) ?>
                    </span>
                    <span class="meta">
                        <?= (int)$p['doc_count'] ?> document<?= (int)$p['doc_count'] === 1 ? '' : 's' ?>
                        <?php if ($p['created_by_name']): ?> · started by <?= htmlspecialchars($p['created_by_name']) ?><?php endif; ?>
                    </span>
                    <a class="edit-btn" href="project_view.php?id=<?= (int)$p['id'] ?>">Edit</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
