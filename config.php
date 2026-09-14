<?php
declare(strict_types=1);

// Falls back to the Render Internal Database URL if DATABASE_URL isn't set
// as an environment variable. Internal URLs only resolve inside Render's
// own network — this fallback only works when actually running on Render.
const RENDER_INTERNAL_DATABASE_URL =
    'postgresql://wfcc_website_user:dB7CSZ4RZk21u65PHK5wAbtfTH1QnZ9C@dpg-daflnp9t0dsc73emj8m0-a/wfcc_website';

function wfcc_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $databaseUrl = getenv('DATABASE_URL') ?: RENDER_INTERNAL_DATABASE_URL;
    $parts = parse_url($databaseUrl);

    if ($parts === false || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
        throw new RuntimeException('DATABASE_URL is malformed.');
    }

    $host   = $parts['host'];
    $port   = $parts['port'] ?? 5432;
    $dbName = ltrim($parts['path'], '/');
    $user   = $parts['user'];
    $pass   = $parts['pass'];

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=require";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
/////////////////////////////////////////////////////////////////////////////////////////////////////
const WFCC_PROJECT_STATUSES = ['Ongoing', 'Finished'];

function ensure_projects_table(): void
{
    wfcc_db()->exec(
        "CREATE TABLE IF NOT EXISTS projects (
            id SERIAL PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'Ongoing',
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );
}

function ensure_project_documents_table(): void
{
    wfcc_db()->exec(
        "CREATE TABLE IF NOT EXISTS project_documents (
            id SERIAL PRIMARY KEY,
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            file_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INTEGER NOT NULL,
            file_data BYTEA NOT NULL,
            uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );
}

//////////////////////////////////////////////////////////////////////////////////////////////
// The fixed set of roles the system recognizes. Kept in one place so the
// register/login flow, the accounts page dropdown, and any future
// permission checks all draw from the same list.
const WFCC_ROLES = [
    'Admin', 'Architect', 'HR', 'Procurement', 'Finance',
    'Accountant', 'Engineer', 'Worker', 'Applicant',
];

function ensure_users_table(): void
{
    $pdo = wfcc_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id              SERIAL PRIMARY KEY,
            full_name       VARCHAR(150) NOT NULL,
            email           VARCHAR(150) NOT NULL UNIQUE,
            password_hash   VARCHAR(255) NOT NULL,
            role            VARCHAR(20) NOT NULL DEFAULT 'Applicant',
            created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );
    // Migration safety net for databases created before roles existed —
    // adds the column with a safe default if it isn't there yet.
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'Applicant'");
}

function is_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'Admin';
}

// Projects section is open to every role except Worker.
function can_view_projects(array $user): bool
{
    return ($user['role'] ?? '') !== 'Worker';
}

function ensure_attendance_table(): void
{
    wfcc_db()->exec(
        'CREATE TABLE IF NOT EXISTS attendance (
            id          SERIAL PRIMARY KEY,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            log_date    DATE NOT NULL,
            time_in     TIMESTAMPTZ,
            time_out    TIMESTAMPTZ,
            UNIQUE (user_id, log_date)
        )'
    );
}

// The server (Render) runs in UTC. Without this, "today" would flip over at
// 8:00am Philippine time instead of midnight. Every date used for attendance
// tabs/records goes through this function so it's always Asia/Manila time,
// regardless of what timezone the server itself is in.
function wfcc_now(): DateTime
{
    return new DateTime('now', new DateTimeZone('Asia/Manila'));
}

function wfcc_today(): string
{
    return wfcc_now()->format('Y-m-d');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}
