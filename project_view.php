<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$currentUser = $_SESSION['user'];

ensure_projects_table();
ensure_project_documents_table();
$pdo = wfcc_db();

$projectId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$stmt->execute(['id' => $projectId]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: projects.php');
    exit;
}

// Serve a document download. Handled first, before any HTML is emitted.
if (isset($_GET['download'])) {
    $docId = (int)$_GET['download'];
    $docStmt = $pdo->prepare('SELECT * FROM project_documents WHERE id = :id AND project_id = :pid');
    $docStmt->execute(['id' => $docId, 'pid' => $projectId]);
    $doc = $docStmt->fetch();
    if (!$doc) {
        http_response_code(404);
        exit('Document not found.');
    }
    $data = $doc['file_data'];
    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . (string)strlen($data));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $doc['file_name']) . '"');
    echo $data;
    exit;
}

const WFCC_MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // 15MB — keep this <= your php.ini upload_max_filesize/post_max_size

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $do = (string)($_POST['do'] ?? '');

        if ($do === 'update_status') {
            $newStatus = (string)($_POST['status'] ?? '');
            if (!in_array($newStatus, WFCC_PROJECT_STATUSES, true)) {
                $error = 'Not a valid status.';
            } else {
                $upd = $pdo->prepare('UPDATE projects SET status = :status WHERE id = :id');
                $upd->execute(['status' => $newStatus, 'id' => $projectId]);
                $project['status'] = $newStatus;
                $success = 'Status updated.';
            }
        } elseif ($do === 'upload_document') {
            if (empty($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Choose a file to upload.';
            } elseif ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Upload failed. Please try again.';
            } elseif ($_FILES['document']['size'] > WFCC_MAX_UPLOAD_BYTES) {
                $error = 'File is too large (15MB max).';
            } else {
                $tmpPath = $_FILES['document']['tmp_name'];
                $fileName = basename($_FILES['document']['name']);
                $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';
                $fileSize = (int)$_FILES['document']['size'];
                $fileData = file_get_contents($tmpPath);

                $ins = $pdo->prepare(
                    'INSERT INTO project_documents (project_id, uploaded_by, file_name, mime_type, file_size, file_data)
                     VALUES (:pid, :uid, :name, :mime, :size, :data)'
                );
                $ins->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $ins->bindValue(':uid', $currentUser['id'], PDO::PARAM_INT);
                $ins->bindValue(':name', $fileName);
                $ins->bindValue(':mime', $mimeType);
                $ins->bindValue(':size', $fileSize, PDO::PARAM_INT);
                $ins->bindValue(':data', $fileData, PDO::PARAM_LOB);
                $ins->execute();

                $success = 'Document uploaded.';
            }
        } elseif ($do === 'delete_document') {
            $docId = (int)($_POST['document_id'] ?? 0);
            $delStmt = $pdo->prepare('SELECT uploaded_by FROM project_documents WHERE id = :id AND project_id = :pid');
            $delStmt->execute(['id' => $docId, 'pid' => $projectId]);
            $doc = $delStmt->fetch();
            if (!$doc) {
                $error = 'Document not found.';
            } elseif ((int)$doc['uploaded_by'] !== (int)$currentUser['id'] && !is_admin($currentUser)) {
                $error = "You can only delete documents you uploaded.";
            } else {
                $pdo->prepare('DELETE FROM project_documents WHERE id = :id')->execute(['id' => $docId]);
                $success = 'Document deleted.';
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$docStmt = $pdo->prepare(
    'SELECT d.id, d.file_name, d.mime_type, d.file_size, d.uploaded_at, d.uploaded_by, u.full_name AS uploaded_by_name
     FROM project_documents d
     LEFT JOIN users u ON u.id = d.uploaded_by
     WHERE d.project_id = :pid
     ORDER BY d.uploaded_at DESC'
);
$docStmt->execute(['pid' => $projectId]);
$documents = $docStmt->fetchAll();

function fmt_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

function fmt_uploaded(string $ts): string
{
    return (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($project['name']) ?> — WFCC</title>
<style>
:root { --blue:#0b3d63; --gray:#f2f4f6; --text:#1f2a33; }
body { margin:0; font-family:'Segoe UI',Roboto,Arial,sans-serif; background:var(--gray); }
.wrap { max-width:900px; margin:40px auto; padding:0 20px; }
.header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:18px; flex-wrap:wrap; gap:8px; }
.header h2 { margin:0 0 4px; color:var(--blue); }
.header a { color:var(--blue); font-weight:600; text-decoration:none; font-size:0.9rem; margin-left:14px; }
.back { font-size:0.8rem; color:#6b7280; text-decoration:none; display:inline-block; margin-bottom:6px; }
.alert { padding:10px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:14px; }
.alert-error { background:#fdecea; color:#b3261e; border:1px solid #f5c2c0; }
.alert-info { background:#eaf0fb; color:#2952a3; border:1px solid #c9d8f5; }
.card { background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 16px rgba(0,0,0,0.06); margin-bottom:20px; }
.card h3 { margin:0 0 14px; color:var(--blue); font-size:1rem; }
.status-row { display:flex; align-items:center; gap:10px; }
select.status-select { padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85rem; font-weight:600; color:var(--blue); background:#fff; }
select.status-select:focus { outline:none; border-color:var(--blue); }
.upload-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.upload-row input[type=file] { font-size:0.85rem; }
.btn { padding:9px 16px; background:var(--blue); color:#fff; border:none; border-radius:8px; font-weight:600; font-size:0.85rem; cursor:pointer; }
.btn:hover { background:#082c48; }
.hint { font-size:0.75rem; color:#9ca3af; margin-top:8px; }
table { width:100%; border-collapse:collapse; font-size:0.85rem; }
th { text-align:left; font-size:0.72rem; text-transform:uppercase; color:#6b7280; padding:8px 6px; border-bottom:1px solid #e5e7eb; }
td { padding:10px 6px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.file-name { font-weight:600; color:var(--text); text-decoration:none; }
.file-name:hover { text-decoration:underline; }
.meta-sm { font-size:0.75rem; color:#9ca3af; }
.btn-delete { padding:5px 10px; border-radius:6px; border:1.5px solid #b3261e; background:#fff; color:#b3261e; font-weight:600; font-size:0.75rem; cursor:pointer; }
.btn-delete:hover { background:#b3261e; color:#fff; }
.empty { color:#9ca3af; font-size:0.9rem; }
</style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div>
            <a class="back" href="projects.php">&larr; All Projects</a>
            <h2><?= htmlspecialchars($project['name']) ?></h2>
        </div>
        <div>
            <a href="home.php">Home</a>
            <a href="logout.php">Log out</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card">
        <h3>Status</h3>
        <form method="POST" class="status-row">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="do" value="update_status">
            <select name="status" class="status-select" onchange="this.form.submit()">
                <?php foreach (WFCC_PROJECT_STATUSES as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $project['status'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn">Save</button></noscript>
        </form>
    </div>

    <div class="card">
        <h3>Post a document</h3>
        <form method="POST" enctype="multipart/form-data" class="upload-row">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="do" value="upload_document">
            <input type="file" name="document" required>
            <button type="submit" class="btn">Upload</button>
        </form>
        <p class="hint">Max file size 15MB.</p>
    </div>

    <div class="card">
        <h3>Documents</h3>
        <?php if (!$documents): ?>
            <p class="empty">No documents posted yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>File</th><th>Posted by</th><th>Date</th><th>Size</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $d): ?>
                        <?php $canDelete = (int)$d['uploaded_by'] === (int)$currentUser['id'] || is_admin($currentUser); ?>
                        <tr>
                            <td>
                                <a class="file-name" href="project_view.php?id=<?= $projectId ?>&download=<?= (int)$d['id'] ?>">
                                    <?= htmlspecialchars($d['file_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($d['uploaded_by_name'] ?? 'Unknown') ?></td>
                            <td class="meta-sm"><?= htmlspecialchars(fmt_uploaded($d['uploaded_at'])) ?></td>
                            <td class="meta-sm"><?= htmlspecialchars(fmt_size((int)$d['file_size'])) ?></td>
                            <td>
                                <?php if ($canDelete): ?>
                                    <form method="POST" onsubmit="return confirm('Delete this document? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="do" value="delete_document">
                                        <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="btn-delete">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
