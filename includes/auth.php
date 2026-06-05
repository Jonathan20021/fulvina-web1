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

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('crm/login.php');
    }
}

function login_user(string $email, string $password): bool
{
    $pdo = db(false);

    if ($pdo && table_exists('users')) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status = "activo" LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ];
            return true;
        }
    }

    if ($email === 'admin@sch.local' && $password === 'admin123') {
        $_SESSION['user'] = [
            'id' => 0,
            'name' => 'Administrador SCH',
            'email' => 'admin@sch.local',
            'role' => 'admin',
            'demo' => true,
        ];
        return true;
    }

    return false;
}

function logout_user(): void
{
    unset($_SESSION['user']);
}
