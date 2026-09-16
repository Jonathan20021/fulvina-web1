<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
verify_csrf();

$user = current_user();
$uid = (int) ($user['id'] ?? 0);
$isDemo = !empty($user['demo']) || !(db(false) && table_exists('users'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isDemo) {
        flash('warning', 'El usuario demo no se puede modificar. Ejecuta install.php y entra con un usuario real.');
        redirect('crm/perfil.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $row = fetch_one('SELECT * FROM users WHERE id=?', [$uid]);
    if (!$row) {
        flash('warning', 'No se encontró tu usuario.');
        redirect('crm/perfil.php');
    }

    if ($name === '' || $email === '') {
        flash('warning', 'Nombre y correo son obligatorios.');
        redirect('crm/perfil.php');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('warning', 'El correo no es válido. Con él inicias sesión y te llega el código de acceso.');
        redirect('crm/perfil.php');
    }
    /* Cambiar el correo pide la contraseña actual. El código de acceso llega por
       correo: quien tuviera un minuto frente a una sesión abierta podía poner el
       suyo, dejar fuera al dueño en su siguiente inicio y quedarse con el segundo
       factor de la cuenta. */
    $changeEmail = strcasecmp($email, (string) $row['email']) !== 0;
    if ($changeEmail && !password_verify($current, (string) $row['password_hash'])) {
        flash('warning', 'Para cambiar tu correo escribe tu contraseña actual.');
        redirect('crm/perfil.php');
    }

    $changePass = $new !== '' || $confirm !== '';
    if ($changePass) {
        if (!password_verify($current, (string) $row['password_hash'])) {
            flash('warning', 'La contraseña actual no es correcta.');
            redirect('crm/perfil.php');
        }
        if (strlen($new) < 8) {
            flash('warning', 'La nueva contraseña debe tener al menos 8 caracteres.');
            redirect('crm/perfil.php');
        }
        if ($new !== $confirm) {
            flash('warning', 'La confirmación de la nueva contraseña no coincide.');
            redirect('crm/perfil.php');
        }
    }

    try {
        if ($changePass) {
            db()->prepare('UPDATE users SET name=?, email=?, password_hash=?, updated_at=NOW() WHERE id=?')
                ->execute([$name, $email, password_hash($new, PASSWORD_DEFAULT), $uid]);
            if (column_exists('users', 'must_change_password')) {
                try { db()->prepare('UPDATE users SET must_change_password=0 WHERE id=?')->execute([$uid]); } catch (Throwable) { /* ignore */ }
            }
            unset($_SESSION['user']['must_change_password']);
            session_regenerate_id(true);
        } else {
            db()->prepare('UPDATE users SET name=?, email=?, updated_at=NOW() WHERE id=?')->execute([$name, $email, $uid]);
        }
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['email'] = $email;
        log_activity('user', $uid, 'perfil_actualizado', null);
        flash('success', $changePass ? 'Perfil y contraseña actualizados.' : 'Perfil actualizado.');
    } catch (Throwable) {
        flash('error', 'No se pudo guardar. Verifica que el correo no esté en uso.');
    }
    redirect('crm/perfil.php');
}

$me = $isDemo ? $user : (fetch_one('SELECT name, email, role, created_at FROM users WHERE id=?', [$uid]) ?: $user);

$crmTitle = 'Mi perfil';
require_once __DIR__ . '/../includes/crm_header.php';
?>
<?= sch_encabezado('Mi perfil', 'Tus datos de acceso') ?>


<?php if ($isDemo): ?>
    <div class="gas-aviso">Estás usando el acceso demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> y entra con un usuario real para editar tu perfil.</div>
<?php endif; ?>

<div class="crm-module-grid" style="max-width:720px;margin-inline:auto">
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2>Mi perfil</h2>
                <p>Actualiza tu nombre, correo y contraseña de acceso.</p>
            </div>
            <span class="status-chip <?= e(status_class((string) ($me['role'] ?? ''))) ?>"><?= e(status_label((string) ($me['role'] ?? 'usuario'))) ?></span>
        </div>
        <form method="post" class="crm-card__body crm-module-grid">
            <?= csrf_field() ?>
            <div class="crm-form-grid">
                <label class="crm-field"><span class="required">Nombre</span><input name="name" required value="<?= e((string) ($me['name'] ?? '')) ?>" class="crm-input" <?= $isDemo ? 'disabled' : '' ?>></label>
                <label class="crm-field"><span class="required">Correo</span><input type="email" name="email" required value="<?= e((string) ($me['email'] ?? '')) ?>" class="crm-input" <?= $isDemo ? 'disabled' : '' ?>><small style="color:var(--muted);font-size:.75rem">Para cambiarlo, escribe también tu contraseña actual abajo: a este correo llega tu código de acceso.</small></label>
            </div>

            <p class="dash-section-label" style="margin:.4rem 0 0">Cambiar contraseña <span style="font-weight:500;color:var(--muted)">(opcional)</span></p>
            <label class="crm-field"><span>Contraseña actual</span><input type="password" name="current_password" class="crm-input" autocomplete="current-password" <?= $isDemo ? 'disabled' : '' ?>></label>
            <div class="crm-form-grid">
                <label class="crm-field"><span>Nueva contraseña</span><input type="password" name="new_password" minlength="8" class="crm-input" autocomplete="new-password" placeholder="Mínimo 8 caracteres" <?= $isDemo ? 'disabled' : '' ?>></label>
                <label class="crm-field"><span>Confirmar nueva</span><input type="password" name="confirm_password" minlength="8" class="crm-input" autocomplete="new-password" <?= $isDemo ? 'disabled' : '' ?>></label>
            </div>
            <div class="crm-toolbar">
                <button class="crm-primary-btn" type="submit" <?= $isDemo ? 'disabled' : '' ?>><i data-lucide="save" class="h-4 w-4"></i>Guardar cambios</button>
            </div>
        </form>
    </article>
</div>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
