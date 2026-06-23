<?php

declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function current_role(): string
{
    return (string) ($_SESSION['user']['role'] ?? '');
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('crm/login.php');
    }
}

/** Authorization gate: must be logged in AND hold one of the given roles. */
function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        exit('No autorizado.');
    }
}

/* ============================================================
   Login throttle (brute-force / credential-stuffing protection)
   Keyed by both source IP (IPv6 grouped to /64) and target email.
   DB-backed, with a session fallback when no DB is available.
   ============================================================ */

function login_ip_key(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (str_contains($ip, ':')) { // IPv6: group by /64 so address rotation cannot reset the counter
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            $masked = substr($bin, 0, 8) . str_repeat("\0", 8);
            $pretty = @inet_ntop($masked);
            if ($pretty !== false) {
                return $pretty . '/64';
            }
        }
    }
    return $ip;
}

function login_email_key(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function login_attempts_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(64) NOT NULL,
        email_hash CHAR(64) NULL,
        attempted_at DATETIME NOT NULL,
        INDEX idx_ip_time (ip, attempted_at),
        INDEX idx_email_time (email_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // Migrate an older table that predates the email_hash column.
    if (!column_exists('login_attempts', 'email_hash')) {
        try {
            $pdo->exec('ALTER TABLE login_attempts ADD COLUMN email_hash CHAR(64) NULL');
            $pdo->exec('ALTER TABLE login_attempts ADD INDEX idx_email_time (email_hash, attempted_at)');
        } catch (Throwable) {
            /* ignore */
        }
    }
}

function login_recent_failures(string $ipKey): int
{
    $pdo = db(false);
    if ($pdo) {
        try {
            login_attempts_table($pdo);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
            $stmt->execute([$ipKey]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            /* fall back to session */
        }
    }
    $now = time();
    $fails = array_filter($_SESSION['login_fails'] ?? [], static fn ($t) => $t > $now - 900);
    $_SESSION['login_fails'] = array_values($fails);
    return count($fails);
}

function login_recent_failures_for_email(string $emailHash): int
{
    $pdo = db(false);
    if (!$pdo) {
        return 0;
    }
    try {
        login_attempts_table($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
        $stmt->execute([$emailHash]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function login_record_failure(string $ipKey, ?string $emailHash = null): void
{
    $pdo = db(false);
    if ($pdo) {
        try {
            login_attempts_table($pdo);
            $pdo->prepare('INSERT INTO login_attempts (ip, email_hash, attempted_at) VALUES (?, ?, NOW())')->execute([$ipKey, $emailHash]);
            return;
        } catch (Throwable) {
            /* fall back to session */
        }
    }
    $_SESSION['login_fails'][] = time();
}

function login_clear_failures(string $ipKey, ?string $emailHash = null): void
{
    unset($_SESSION['login_fails']);
    $pdo = db(false);
    if (!$pdo) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ipKey]);
        if ($emailHash !== null) {
            $pdo->prepare('DELETE FROM login_attempts WHERE email_hash = ?')->execute([$emailHash]);
        }
    } catch (Throwable) {
        /* ignore */
    }
}

/**
 * Lightweight anti-abuse gate for public, unauthenticated form submissions
 * (contact, support). Reuses the login_attempts table under a namespaced key
 * (e.g. "form:contacto:<ip>") so it never interferes with the login throttle.
 * Returns false when the per-IP submission rate is exceeded; records the
 * attempt when allowed.
 */
function form_throttle_ok(string $tag, int $max = 6): bool
{
    $ipKey = 'form:' . $tag . ':' . login_ip_key();
    if (login_recent_failures($ipKey) >= $max) {
        return false;
    }
    login_record_failure($ipKey);
    return true;
}

/* ============================================================
   Authentication
   ============================================================ */

/**
 * Validate credentials WITHOUT creating a session. Returns the user payload
 * (id, name, email, role, [demo]) on success, or null. The login flow uses this
 * so an email OTP step can run between the password check and session creation.
 */
function authenticate_user(string $email, string $password): ?array
{
    $pdo = db(false);

    if ($pdo && table_exists('users')) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status = "activo" LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string) $user['password_hash'])) {
            // Transparently upgrade the stored hash if algorithm/cost changed.
            if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                try {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
                } catch (Throwable) {
                    /* non-fatal */
                }
            }
            return [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
            ];
        }

        // Database reachable: ONLY a real, verified, active user may enter.
        return null;
    }

    // No database. Allow the seed admin ONLY on a genuine local host: is_local_env()
    // now also requires a loopback REMOTE_ADDR, so a public host can never reach this
    // branch by spoofing the Host header.
    if (is_local_env() && $email === 'admin@sch.local' && hash_equals('admin123', $password)) {
        return [
            'id' => 0,
            'name' => 'Administrador SCH',
            'email' => 'admin@sch.local',
            'role' => 'admin',
            'demo' => true,
        ];
    }

    return null;
}

/** Establish the authenticated session for a user payload (rotates the id). */
function establish_session(array $user): void
{
    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user'] = [
        'id' => (int) ($user['id'] ?? 0),
        'name' => $user['name'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? '',
    ];
    if (!empty($user['demo'])) {
        $_SESSION['user']['demo'] = true;
    }
    if (!empty($user['must_change_password'])) {
        $_SESSION['user']['must_change_password'] = true;
    }
}

/** Validate credentials and log the user in directly (no OTP). Kept for callers
 *  that don't run the two-step flow. */
function login_user(string $email, string $password): bool
{
    $user = authenticate_user($email, $password);
    if ($user === null) {
        return false;
    }
    establish_session($user);
    return true;
}

function logout_user(): void
{
    unset($_SESSION['user']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true); // rotate id so the old session cannot be reused
    }
}
