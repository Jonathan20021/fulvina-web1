<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
verify_csrf();

$user = current_user();
$uid = (int) ($user['id'] ?? 0);

// Demo / no-DB users cannot change a stored password.
if (!empty($user['demo']) || !(db(false) && table_exists('users'))) {
    redirect('crm/index.php');
}
ensure_rbac_schema(); // guarantees the must_change_password column exists

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (strlen($new) < 8) {
        $_SESSION['pwchange_error'] = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($new !== $confirm) {
        $_SESSION['pwchange_error'] = 'Las contraseñas no coinciden.';
    } else {
        try {
            db()->prepare('UPDATE users SET password_hash=?, must_change_password=0, updated_at=NOW() WHERE id=?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            unset($_SESSION['user']['must_change_password']);
            session_regenerate_id(true); // rotate the session after a credential change
            log_activity('user', $uid, 'password_obligatoria_cambiada', null);
            flash('success', 'Contraseña actualizada. ¡Listo para trabajar!');
        } catch (Throwable) {
            $_SESSION['pwchange_error'] = 'No se pudo guardar la contraseña. Intenta de nuevo.';
        }
    }
}

redirect('crm/index.php');
