<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('config.manage');
verify_csrf();
if (db(false)) { ensure_rbac_schema(); }

$hasDb = db(false) && table_exists('users');
$modules = rbac_modules();
$mandatory = rbac_mandatory_caps();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');

    if ($form === 'save_roles') {
        $postedCaps = (array) ($_POST['caps'] ?? []);
        $postedLabels = (array) ($_POST['label'] ?? []);
        $roles = [];
        foreach (rbac_roles() as $key => $def) {
            if ($key === 'admin') { continue; }
            $roles[$key] = [
                'label' => trim((string) ($postedLabels[$key] ?? $def['label'])),
                'caps' => (array) ($postedCaps[$key] ?? []),
            ];
        }
        rbac_save_roles($roles);
        log_activity('role', null, 'permisos_actualizados', implode(',', array_keys($roles)));
        flash('success', 'Permisos de roles actualizados.');
        redirect('crm/roles.php');
    }

    if ($form === 'add_role') {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', (string) ($_POST['role_key'] ?? ''))));
        $label = trim((string) ($_POST['role_label'] ?? ''));
        if ($key === '' && $label !== '') {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', $label)));
        }
        if ($key === '' || $key === 'admin') {
            flash('warning', 'Indica un identificador válido para el rol.');
        } else {
            $roles = rbac_roles();
            unset($roles['admin']);
            if (isset($roles[$key])) {
                flash('warning', 'Ya existe un rol con ese identificador.');
            } else {
                $roles[$key] = ['label' => $label !== '' ? $label : ucfirst($key), 'caps' => $mandatory];
                rbac_save_roles($roles);
                log_activity('role', null, 'rol_creado', $key);
                flash('success', 'Rol “' . ($label ?: $key) . '” creado. Asigna sus permisos abajo.');
            }
        }
        redirect('crm/roles.php');
    }

    if ($form === 'delete_role') {
        $key = (string) ($_POST['role_key'] ?? '');
        if ($key !== '' && $key !== 'admin') {
            $inUse = $hasDb ? (int) (fetch_one('SELECT COUNT(*) c FROM users WHERE role = ?', [$key])['c'] ?? 0) : 0;
            if ($inUse > 0) {
                flash('warning', 'No se puede eliminar: ' . $inUse . ' usuario(s) tienen este rol. Reasígnalos primero.');
            } else {
                $roles = rbac_roles();
                unset($roles['admin'], $roles[$key]);
                rbac_save_roles($roles);
                log_activity('role', null, 'rol_eliminado', $key);
                flash('success', 'Rol eliminado.');
            }
        }
        redirect('crm/roles.php');
    }
}

$roles = rbac_roles();
$userCountByRole = [];
if ($hasDb) {
    foreach (fetch_all('SELECT role, COUNT(*) c FROM users GROUP BY role') as $r) {
        $userCountByRole[(string) $r['role']] = (int) $r['c'];
    }
}

$crmTitle = 'Roles y permisos';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a>; en producción los cambios de permisos se guardan en la base de datos.</div>
<?php endif; ?>

<section class="crm-cockpit">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero">
            <span class="crm-kicker"><i data-lucide="shield-check"></i>Control de acceso</span>
            <h2>Roles y permisos por módulo.</h2>
            <p>Define qué puede ver y hacer cada rol en cada módulo. El rol <strong>Administrador</strong> siempre tiene acceso total. Los cambios aplican de inmediato a todos los usuarios de ese rol.</p>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de roles">
            <article><span>Roles</span><strong><?= e((string) count($roles)) ?></strong><small>incl. Administrador</small></article>
            <article><span>Módulos</span><strong><?= e((string) count($modules)) ?></strong><small>con permisos</small></article>
            <article><span>Permisos</span><strong><?= e((string) count(rbac_all_caps())) ?></strong><small>asignables</small></article>
            <article><span>Usuarios</span><strong><?= e((string) array_sum($userCountByRole)) ?></strong><small>con rol asignado</small></article>
        </div>
    </div>

    <!-- Crear rol -->
    <article class="crm-card">
        <div class="crm-card__head">
            <div><h2>Crear rol</h2><p>Agrega un rol nuevo y luego marca sus permisos en la matriz.</p></div>
        </div>
        <form method="post" class="crm-card__body" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="add_role">
            <label class="crm-field" style="flex:1 1 240px"><span>Nombre del rol</span><input name="role_label" class="crm-input" placeholder="Ej. Biomédico, Recepción, Gerencia" required></label>
            <label class="crm-field" style="flex:0 1 200px"><span>Identificador (opcional)</span><input name="role_key" class="crm-input" placeholder="se genera del nombre"></label>
            <button type="submit" class="crm-primary-btn"><i data-lucide="plus" class="h-4 w-4"></i>Crear rol</button>
        </form>
    </article>

    <!-- Matriz de permisos -->
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="save_roles">
        <article class="crm-data-surface">
            <div class="crm-data-surface__head">
                <div><h3>Matriz de permisos</h3><p>Marca lo que cada rol puede hacer. “Panel · Ver” es obligatorio para todos.</p></div>
                <button type="submit" class="crm-primary-btn"><i data-lucide="save" class="h-4 w-4"></i>Guardar permisos</button>
            </div>
            <div class="crm-table-wrap">
                <table class="crm-table rbac-matrix">
                    <thead>
                        <tr>
                            <th>Permiso</th>
                            <?php foreach ($roles as $rk => $rdef): ?>
                                <th class="rbac-col">
                                    <?php if ($rk === 'admin'): ?>
                                        <span class="rbac-role-name">Administrador</span>
                                        <span class="rbac-role-sub">acceso total</span>
                                    <?php else: ?>
                                        <input class="rbac-label-input" name="label[<?= e($rk) ?>]" value="<?= e($rdef['label']) ?>" aria-label="Nombre del rol <?= e($rk) ?>">
                                        <span class="rbac-role-sub"><?= e((string) ($userCountByRole[$rk] ?? 0)) ?> usuario(s)
                                            <?php if (($userCountByRole[$rk] ?? 0) === 0): ?>
                                                · <button type="submit" form="delrole-<?= e($rk) ?>" class="rbac-del" title="Eliminar rol">eliminar</button>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modules as $mkey => [$mlabel, $actions]): ?>
                            <tr class="rbac-modrow"><td colspan="<?= count($roles) + 1 ?>"><i data-lucide="<?= e(['panel'=>'layout-dashboard','clientes'=>'building-2','cotizaciones'=>'file-text','leads'=>'inbox','equipos'=>'monitor','tickets'=>'life-buoy','agenda'=>'calendar-days','reportes'=>'bar-chart-3','usuarios'=>'users-round','config'=>'settings'][$mkey] ?? 'dot') ?>"></i><?= e($mlabel) ?></td></tr>
                            <?php foreach ($actions as $action): $cap = $mkey . '.' . $action; $isMandatory = in_array($cap, $mandatory, true); ?>
                                <tr>
                                    <td class="rbac-cap"><?= e(rbac_action_label($action)) ?></td>
                                    <?php foreach ($roles as $rk => $rdef): ?>
                                        <?php
                                            $isAdmin = $rk === 'admin';
                                            $checked = $isAdmin || $isMandatory || in_array($cap, $rdef['caps'], true);
                                            $disabled = $isAdmin || $isMandatory;
                                        ?>
                                        <td class="rbac-col">
                                            <input type="checkbox" class="rbac-cb"
                                                <?= $disabled ? '' : 'name="caps[' . e($rk) . '][]"' ?>
                                                value="<?= e($cap) ?>"
                                                <?= $checked ? 'checked' : '' ?>
                                                <?= $disabled ? 'disabled' : '' ?>
                                                aria-label="<?= e($mlabel . ' ' . rbac_action_label($action) . ' — ' . $rdef['label']) ?>">
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="crm-toolbar" style="justify-content:flex-end;padding:.85rem 1.1rem">
                <button type="submit" class="crm-primary-btn"><i data-lucide="save" class="h-4 w-4"></i>Guardar permisos</button>
            </div>
        </article>
    </form>

    <!-- Forms de eliminación de rol (fuera de la matriz para no anidar forms) -->
    <?php foreach ($roles as $rk => $rdef): ?>
        <?php if ($rk !== 'admin' && ($userCountByRole[$rk] ?? 0) === 0): ?>
            <form id="delrole-<?= e($rk) ?>" method="post" onsubmit="return confirm('¿Eliminar el rol <?= e(addslashes($rdef['label'])) ?>?');" style="display:none">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="delete_role">
                <input type="hidden" name="role_key" value="<?= e($rk) ?>">
            </form>
        <?php endif; ?>
    <?php endforeach; ?>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
