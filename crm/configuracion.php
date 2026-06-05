<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin'); // user management + global settings are admin-only
verify_csrf();

$hasDb = db(false) && table_exists('users');
if ($hasDb) { ensure_settings_schema(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && ($_POST['form'] ?? '') === 'settings') {
    setting_set('quote_terms', trim((string) ($_POST['quote_terms'] ?? '')));
    $rate = (float) ($_POST['quote_exchange_rate'] ?? 0);
    setting_set('quote_exchange_rate', (string) ($rate > 0 ? $rate : 1));
    flash('success', 'Preferencias de cotización guardadas.');
    redirect('crm/configuracion.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && isset($_POST['delete_id'])) {
    $did = (int) $_POST['delete_id'];
    $me = (int) (current_user()['id'] ?? 0);
    if ($did > 0 && $did === $me) {
        flash('warning', 'No puedes eliminar tu propio usuario.');
    } elseif ($did > 0) {
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$did]);
        flash('success', 'Usuario eliminado.');
    }
    redirect('crm/configuracion.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && ($_POST['form'] ?? '') === 'user') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? 'soporte'));
    if (!in_array($role, ['admin', 'ventas', 'soporte', 'ingenieria'], true)) {
        $role = 'soporte';
    }
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || $email === '' || strlen($password) < 8) {
        flash('warning', 'Nombre, correo y contrasena de 8 caracteres son obligatorios.');
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, "activo", NOW(), NOW())');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            flash('success', 'Usuario creado.');
            redirect('crm/configuracion.php');
        } catch (Throwable $e) {
            flash('warning', 'No se pudo crear usuario. Verifica que el correo no exista.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para crear usuarios reales.');
}

$users = $hasDb ? fetch_all('SELECT id, name, email, role, status, created_at FROM users ORDER BY created_at DESC') : [
    ['id' => 0, 'name' => 'Administrador SCH', 'email' => 'admin@sch.local', 'role' => 'admin', 'status' => 'demo', 'created_at' => date('Y-m-d')],
];

$quoteTermsSetting = setting_get('quote_terms', quote_default_terms());
$quoteRateSetting = setting_get('quote_exchange_rate', '60');

$crmTitle = 'Configuracion';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para crear usuarios y tablas.</div>
<?php endif; ?>

<div class="crm-module-grid">
    <article class="crm-card" x-data="crmFormModal({id:0,name:'',email:'',role:'soporte',password:''})">
        <div class="crm-card__head">
            <div>
                <h2>Usuarios</h2>
                <p>Acceso interno para ventas, soporte, ingenieria y administracion.</p>
            </div>
            <?php if ($hasDb): ?>
                <button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="user-plus" class="h-4 w-4"></i>Nuevo usuario</button>
            <?php endif; ?>
        </div>
        <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Correo</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th class="text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><strong><?= e($user['name']) ?></strong></td>
                                <td><?= e($user['email']) ?></td>
                                <td><?= e($user['role']) ?></td>
                                <td><span class="status-chip <?= e(status_class($user['status'])) ?>"><?= e($user['status']) ?></span></td>
                                <td class="text-right">
                                    <?php if ($hasDb && (int) $user['id'] !== (int) (current_user()['id'] ?? -1)): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar a <?= e(addslashes($user['name'])) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_id" value="<?= (int) $user['id'] ?>">
                                            <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="dash-sub" style="color:var(--muted);font-size:.74rem">Tú</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <dialog x-ref="dlg" class="crm-modal" @click.self="close()" @cancel.prevent="close()">
                <form method="post" class="crm-modal__form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="user">
                    <header class="crm-modal__head">
                        <span class="crm-modal__icon"><i data-lucide="user-plus"></i></span>
                        <div class="crm-modal__titles">
                            <h2>Nuevo usuario</h2>
                            <p>Alta de acceso interno con rol operativo.</p>
                        </div>
                        <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
                    </header>
                    <div class="crm-modal__body">
                        <label class="crm-field"><span class="required">Nombre</span><input name="name" required x-model="form.name" class="crm-input"></label>
                        <label class="crm-field"><span class="required">Correo</span><input type="email" name="email" required x-model="form.email" class="crm-input"></label>
                        <div class="crm-form-grid">
                            <label class="crm-field"><span class="required">Rol</span><select name="role" x-model="form.role" class="crm-select"><?php foreach (['admin','ventas','soporte','ingenieria'] as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?></select></label>
                            <label class="crm-field"><span class="required">Contraseña</span><input type="password" name="password" minlength="8" required x-model="form.password" class="crm-input" placeholder="Mínimo 8 caracteres"></label>
                        </div>
                    </div>
                    <footer class="crm-modal__foot">
                        <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                        <button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i>Crear acceso</button>
                    </footer>
                </form>
            </dialog>
        </article>

        <article class="crm-card">
            <div class="crm-card__head">
                <div>
                    <h2>Estado del sistema</h2>
                    <p>Diagnostico local para XAMPP, base de datos y assets.</p>
                </div>
            </div>
            <div class="crm-card__body crm-mini-grid">
                <div class="crm-mini-stat">
                    <span>Base de datos</span>
                    <strong class="<?= $hasDb ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $hasDb ? 'Conectada' : 'Pendiente' ?></strong>
                </div>
                <div class="crm-mini-stat">
                    <span>Assets extraidos</span>
                    <strong><?= file_exists(__DIR__ . '/../assets/media/manifest.json') ? '185 archivos' : 'No encontrado' ?></strong>
                </div>
                <div class="crm-mini-stat">
                    <span>Sitio publico</span>
                    <strong>SEO + JSON-LD</strong>
                </div>
                <div class="crm-mini-stat">
                    <span>Servidor</span>
                    <strong>PHP <?= e(PHP_VERSION) ?></strong>
                </div>
            </div>
        </article>

        <article class="crm-card">
            <div class="crm-card__head">
                <div>
                    <h2>Preferencias de cotización</h2>
                    <p>Valores por defecto que se precargan al crear una cotización (editables por cotización).</p>
                </div>
            </div>
            <form method="post" class="crm-card__body crm-module-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="settings">
                <div class="crm-form-grid">
                    <label class="crm-field">
                        <span>Tasa de cambio por defecto (US$ 1 = RD$)</span>
                        <input type="number" step="0.01" min="0" name="quote_exchange_rate" value="<?= e($quoteRateSetting) ?>" class="crm-input" <?= $hasDb ? '' : 'disabled' ?>>
                    </label>
                </div>
                <label class="crm-field">
                    <span>Términos y condiciones por defecto</span>
                    <textarea name="quote_terms" rows="8" class="crm-textarea" <?= $hasDb ? '' : 'disabled' ?>><?= e($quoteTermsSetting) ?></textarea>
                </label>
                <div class="crm-toolbar">
                    <button class="crm-primary-btn" type="submit" <?= $hasDb ? '' : 'disabled' ?>><i data-lucide="save" class="h-4 w-4"></i>Guardar preferencias</button>
                </div>
            </form>
        </article>
</div>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
