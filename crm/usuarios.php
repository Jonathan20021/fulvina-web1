<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('usuarios.manage');
verify_csrf();
if (db(false)) { ensure_rbac_schema(); }

$hasDb = db(false) && table_exists('users');
$roles = rbac_roles();
$roleKeys = array_keys($roles);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
    $me = (int) (current_user()['id'] ?? 0);

    if (isset($_POST['delete_id'])) {
        $did = (int) $_POST['delete_id'];
        $target = $did > 0 ? fetch_one('SELECT role, status FROM users WHERE id=?', [$did]) : null;
        if ($did > 0 && $did === $me) {
            flash('warning', 'No puedes eliminar tu propio usuario.');
        } elseif ($target && ($target['role'] ?? '') === 'admin' && ($target['status'] ?? '') === 'activo' && active_admin_count() <= 1) {
            flash('warning', 'No puedes eliminar al último administrador activo.');
        } elseif ($did > 0) {
            db()->prepare('DELETE FROM users WHERE id=?')->execute([$did]);
            log_activity('user', $did, 'usuario_eliminado', null);
            flash('success', 'Usuario eliminado.');
        }
        redirect('crm/usuarios.php');
    }

    if (($_POST['form'] ?? '') === 'user_edit') {
        $uid = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'soporte'));
        if (!in_array($role, $roleKeys, true)) { $role = 'soporte'; }
        $status = in_array(($_POST['status'] ?? 'activo'), ['activo', 'inactivo'], true) ? $_POST['status'] : 'activo';
        $password = (string) ($_POST['password'] ?? '');

        $cur = fetch_one('SELECT role, status FROM users WHERE id=?', [$uid]);
        $wasAdmin = ($cur['role'] ?? '') === 'admin';
        $losesAdmin = $wasAdmin && ($role !== 'admin' || $status !== 'activo');
        if ($uid <= 0 || $name === '' || $email === '') {
            flash('warning', 'Nombre y correo son obligatorios.');
        } elseif ($role === 'admin' && current_role() !== 'admin') {
            flash('warning', 'Solo un administrador puede asignar el rol Administrador.');
        } elseif ($uid === $me && $losesAdmin) {
            flash('warning', 'No puedes quitarte a ti mismo el rol admin ni desactivarte.');
        } elseif ($losesAdmin && active_admin_count() <= 1) {
            flash('warning', 'Debe quedar al menos un administrador activo en el sistema.');
        } elseif ($password !== '' && strlen($password) < 8) {
            flash('warning', 'La contraseña debe tener al menos 8 caracteres.');
        } else {
            try {
                if ($password !== '') {
                    db()->prepare('UPDATE users SET name=?, email=?, role=?, status=?, password_hash=?, updated_at=NOW() WHERE id=?')
                        ->execute([$name, $email, $role, $status, password_hash($password, PASSWORD_DEFAULT), $uid]);
                } else {
                    db()->prepare('UPDATE users SET name=?, email=?, role=?, status=?, updated_at=NOW() WHERE id=?')
                        ->execute([$name, $email, $role, $status, $uid]);
                }
                log_activity('user', $uid, 'usuario_actualizado', $name);
                flash('success', 'Usuario actualizado.');
            } catch (Throwable) {
                flash('warning', 'No se pudo actualizar. Verifica que el correo no esté en uso.');
            }
        }
        redirect('crm/usuarios.php');
    }

    if (($_POST['form'] ?? '') === 'user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'soporte'));
        if (!in_array($role, $roleKeys, true)) { $role = 'soporte'; }
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '' || $email === '' || strlen($password) < 8) {
            flash('warning', 'Nombre, correo y contraseña de 8 caracteres son obligatorios.');
        } elseif ($role === 'admin' && current_role() !== 'admin') {
            flash('warning', 'Solo un administrador puede crear usuarios con rol Administrador.');
            redirect('crm/usuarios.php');
        } else {
            try {
                db()->prepare('INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, "activo", NOW(), NOW())')
                    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                log_activity('user', (int) db()->lastInsertId(), 'usuario_creado', $name);
                flash('success', 'Usuario creado.');
                redirect('crm/usuarios.php');
            } catch (Throwable $e) {
                flash('warning', 'No se pudo crear usuario. Verifica que el correo no exista.');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para crear usuarios reales.');
}

$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
if ($roleFilter !== '' && !in_array($roleFilter, $roleKeys, true)) { $roleFilter = ''; }

if ($hasDb) {
    $where = '1=1';
    $params = [];
    if ($q !== '') { $where .= ' AND (name LIKE ? OR email LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%"); }
    if ($roleFilter !== '') { $where .= ' AND role = ?'; $params[] = $roleFilter; }
    $users = fetch_all("SELECT id, name, email, role, status, created_at FROM users WHERE {$where} ORDER BY (status='activo') DESC, name ASC", $params);
    $userTotal = (int) (fetch_one('SELECT COUNT(*) c FROM users')['c'] ?? 0);
    $userActive = db_count('users', "status='activo'");
    $userAdmins = db_count('users', "role='admin'");
} else {
    $users = [
        ['id' => 0, 'name' => 'Administrador SCH', 'email' => 'admin@sch.local', 'role' => 'admin', 'status' => 'demo', 'created_at' => date('Y-m-d')],
    ];
    $userTotal = 1; $userActive = 1; $userAdmins = 1;
}

$meId = (int) (current_user()['id'] ?? -1);
$initialsOf = static function (string $name): string {
    $p = preg_split('/\s+/', trim(preg_replace('/^(Ing\.|Lic\.|Dr\.|Dra\.|Sr\.|Sra\.)\s+/u', '', $name))) ?: [];
    return strtoupper(mb_substr($p[0] ?? 'U', 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : ''));
};
$tones = ['green', 'blue', 'teal', 'gold', 'slate'];

$crmTitle = 'Usuarios y accesos';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para crear usuarios reales.</div>
<?php endif; ?>

<section class="crm-cockpit" x-data="crmFormModal({id:0,name:'',email:'',role:'soporte',status:'activo',password:''})">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero">
            <span class="crm-kicker"><i data-lucide="users-round"></i>Control de acceso</span>
            <h2>Usuarios internos y sus accesos.</h2>
            <p>Gestiona el equipo que entra al CRM: alta, edición, rol y estado. Los permisos de cada rol se definen en Roles y permisos.</p>
            <div class="crm-cockpit__actions">
                <?php if ($hasDb): ?><button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="user-plus" class="h-4 w-4"></i>Nuevo usuario</button><?php endif; ?>
                <?php if (current_can('config.manage')): ?><a href="<?= url('crm/roles.php') ?>" class="crm-secondary-btn"><i data-lucide="shield-check" class="h-4 w-4"></i>Roles y permisos</a><?php endif; ?>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de usuarios">
            <article><span>Total</span><strong><?= e((string) $userTotal) ?></strong><small>usuarios</small></article>
            <article><span>Activos</span><strong><?= e((string) $userActive) ?></strong><small>pueden entrar</small></article>
            <article><span>Administradores</span><strong><?= e((string) $userAdmins) ?></strong><small>acceso total</small></article>
            <article><span>Roles</span><strong><?= e((string) count($roles)) ?></strong><small>definidos</small></article>
        </div>
    </div>

    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div>
                <h3>Directorio de usuarios</h3>
                <p><?= $q !== '' || $roleFilter !== '' ? e((string) count($users)) . ' coincidencia' . (count($users) === 1 ? '' : 's') : 'Equipo con acceso interno al CRM.' ?></p>
            </div>
            <form method="get" class="crm-toolbar" style="flex-wrap:wrap;gap:.5rem">
                <div class="crm-search-field" style="flex:1 1 180px"><i data-lucide="search" class="h-4 w-4"></i><input name="q" value="<?= e($q) ?>" placeholder="Nombre o correo" class="crm-input"></div>
                <select name="role" class="crm-select" style="max-width:180px"><option value="">Todos los roles</option><?php foreach ($roles as $rk => $rdef): ?><option value="<?= e($rk) ?>" <?= $roleFilter === $rk ? 'selected' : '' ?>><?= e($rdef['label']) ?></option><?php endforeach; ?></select>
                <button type="submit" class="crm-secondary-btn"><i data-lucide="filter" class="h-4 w-4"></i>Filtrar</button>
                <?php if ($q !== '' || $roleFilter !== ''): ?><a href="<?= url('crm/usuarios.php') ?>" class="crm-secondary-btn"><i data-lucide="x" class="h-4 w-4"></i>Limpiar</a><?php endif; ?>
            </form>
        </div>
        <div class="crm-table-wrap">
            <table class="crm-table crm-data-table">
                <thead>
                    <tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Creado</th><th class="text-right">Acción</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $i => $user): ?>
                        <tr>
                            <td>
                                <div class="dash-person">
                                    <span class="av av--<?= e($tones[$i % count($tones)]) ?>"><?= e($initialsOf((string) $user['name'])) ?></span>
                                    <span class="dash-person__id"><b><?= e($user['name']) ?><?= (int) $user['id'] === $meId ? ' <small style="color:var(--brand-strong);font-weight:600">· Tú</small>' : '' ?></b><span><?= e($user['email']) ?></span></span>
                                </div>
                            </td>
                            <td><span class="status-chip <?= e(role_class((string) $user['role'])) ?>"><?= e(role_label((string) $user['role'])) ?></span></td>
                            <td><span class="status-chip <?= e(status_class($user['status'])) ?>"><?= e(status_label($user['status'])) ?></span></td>
                            <td class="ops-nowrap"><?= e(date_es($user['created_at'] ?? null)) ?></td>
                            <td class="text-right">
                                <div class="crm-row-actions">
                                    <?php if ($hasDb): ?>
                                        <button type="button" class="crm-icon-action" title="Editar" @click='openEdit(<?= e(json_encode(['id' => (int) $user['id'], 'name' => (string) $user['name'], 'email' => (string) $user['email'], 'role' => (string) $user['role'], 'status' => (string) $user['status'], 'password' => ''])) ?>)'><i data-lucide="pencil"></i></button>
                                        <?php if ((int) $user['id'] !== $meId): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar a <?= e(addslashes($user['name'])) ?>?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="delete_id" value="<?= (int) $user['id'] ?>">
                                                <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$users): ?>
                <div class="crm-empty"><i data-lucide="users-round" class="h-6 w-6"></i><strong>Sin usuarios</strong><p>Crea el primer acceso con “Nuevo usuario”.</p></div>
            <?php endif; ?>
        </div>
    </article>

    <dialog x-ref="dlg" class="crm-modal" @click.self="close()" @cancel.prevent="close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" :value="form.id ? 'user_edit' : 'user'">
            <input type="hidden" name="id" :value="form.id">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="user-plus"></i></span>
                <div class="crm-modal__titles">
                    <h2 x-text="form.id ? 'Editar usuario' : 'Nuevo usuario'">Nuevo usuario</h2>
                    <p>Acceso interno con rol operativo.</p>
                </div>
                <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body">
                <label class="crm-field"><span class="required">Nombre</span><input name="name" required x-model="form.name" class="crm-input"></label>
                <label class="crm-field"><span class="required">Correo</span><input type="email" name="email" required x-model="form.email" class="crm-input"></label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span class="required">Rol</span><select name="role" x-model="form.role" class="crm-select"><?php foreach ($roles as $rk => $rdef): ?><option value="<?= e($rk) ?>"><?= e($rdef['label']) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field" x-show="form.id" x-cloak><span>Estado</span><select name="status" x-model="form.status" class="crm-select"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></label>
                </div>
                <label class="crm-field"><span :class="form.id ? '' : 'required'" x-text="form.id ? 'Nueva contraseña (opcional)' : 'Contraseña'">Contraseña</span><input type="password" name="password" minlength="8" :required="!form.id" x-model="form.password" class="crm-input" :placeholder="form.id ? 'Dejar en blanco para no cambiar' : 'Mínimo 8 caracteres'"></label>
            </div>
            <footer class="crm-modal__foot">
                <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                <button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i><span x-text="form.id ? 'Guardar cambios' : 'Crear acceso'">Crear acceso</span></button>
            </footer>
        </form>
    </dialog>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
