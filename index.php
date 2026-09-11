<?php
declare(strict_types=1);

// Falls back to the Render Internal Database URL if DATABASE_URL isn't set
// as an environment variable. Internal URLs only resolve inside Render's
// own network, so this fallback only works when this file is actually
// running on Render — not on your local PC via XAMPP.
const RENDER_INTERNAL_DATABASE_URL =
    'postgresql://wfcc_website_user:dB7CSZ4RZk21u65PHK5wAbtfTH1QnZ9C@dpg-daflnp9t0dsc73emj8m0-a/wfcc_website';

$databaseUrl = getenv('DATABASE_URL') ?: RENDER_INTERNAL_DATABASE_URL;

$status  = '';
$message = '';

$parts = parse_url($databaseUrl);

if ($parts === false || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
    $status  = 'error';
    $message = 'DATABASE_URL is malformed.';
} else {
    $host   = $parts['host'];
    $port   = $parts['port'] ?? 5432;
    $dbName = ltrim($parts['path'], '/');
    $user   = $parts['user'];
    $pass   = $parts['pass'];

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=require";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $version = $pdo->query('SELECT version()')->fetchColumn();

        $status  = 'success';
        $message = "Connected to \"$dbName\" on $host.\n\n" . $version;
    } catch (Throwable $e) {
        $status  = 'error';
        $message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WFCC — Render DB Connection Test</title>
    <style>
        body {
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
            background: #0b3d63;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .card {
            background: #fff;
            color: #1f2a33;
            padding: 32px;
            border-radius: 12px;
            max-width: 520px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0,0,0,0.25);
        }
        h1 { font-size: 1.1rem; margin: 0 0 16px; color: #0b3d63; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 14px;
        }
        .success { background: #e6f4ea; color: #1e7e34; }
        .error   { background: #fdecea; color: #b3261e; }
        pre {
            background: #f2f4f6;
            padding: 12px;
            border-radius: 8px;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>WFCC — Render Database Connection Test</h1>
        <span class="badge <?= $status ?>"><?= $status === 'success' ? 'CONNECTED' : 'FAILED' ?></span>
        <pre><?= htmlspecialchars($message) ?></pre>
    </div>
</body>
</html>
