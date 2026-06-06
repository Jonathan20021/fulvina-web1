<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('equipos.view');
verify_csrf();

$hasDb = db(false) && table_exists('equipment');
$clients = $hasDb ? fetch_all('SELECT id, name FROM clients ORDER BY name ASC') : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && isset($_POST['delete_id'])) {
        if (!current_can('equipos.delete')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/equipos.php'); }
    $did = (int) $_POST['delete_id'];
    if ($did > 0) {
        db()->prepare('DELETE FROM equipment WHERE id=?')->execute([$did]);
        log_activity('equipment', $did, 'equipo_eliminado', null);
        flash('success', 'Equipo eliminado.');
    }
    redirect('crm/equipos.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
        if (!current_can('equipos.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/equipos.php'); }
    $id = (int) ($_POST['id'] ?? 0);
    $payload = [
        (int) ($_POST['client_id'] ?? 0),
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['brand'] ?? '')),
        trim((string) ($_POST['model'] ?? '')),
        trim((string) ($_POST['serial'] ?? '')),
        trim((string) ($_POST['area'] ?? '')),
        trim((string) ($_POST['location'] ?? '')),
        $_POST['installation_date'] ?: null,
        $_POST['warranty_until'] ?: null,
        trim((string) ($_POST['status'] ?? 'activo')),
        $_POST['next_service_at'] ?: null,
        trim((string) ($_POST['notes'] ?? '')),
    ];

    if ($payload[0] <= 0 || $payload[1] === '') {
        flash('warning', 'Selecciona cliente y nombre del equipo.');
    } elseif ($id > 0) {
        $stmt = db()->prepare('UPDATE equipment SET client_id=?, name=?, brand=?, model=?, serial=?, area=?, location=?, installation_date=?, warranty_until=?, status=?, next_service_at=?, notes=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([...$payload, $id]);
        log_activity('equipment', $id, 'equipo_actualizado', $payload[1]);
        flash('success', 'Equipo actualizado.');
        redirect('crm/equipos.php');
    } else {
        $stmt = db()->prepare('INSERT INTO equipment (client_id, name, brand, model, serial, area, location, installation_date, warranty_until, status, next_service_at, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute($payload);
        log_activity('equipment', (int) db()->lastInsertId(), 'equipo_creado', $payload[1]);
        flash('success', 'Equipo registrado.');
        redirect('crm/equipos.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para guardar equipos en MySQL.');
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $hasDb && $editId > 0 ? fetch_one('SELECT * FROM equipment WHERE id=?', [$editId]) : null;

$equipShape = function (array $x): array {
    return [
        'id' => (int) ($x['id'] ?? 0), 'client_id' => (int) ($x['client_id'] ?? 0), 'name' => (string) ($x['name'] ?? ''),
        'brand' => (string) ($x['brand'] ?? ''), 'model' => (string) ($x['model'] ?? ''), 'serial' => (string) ($x['serial'] ?? ''),
        'area' => (string) ($x['area'] ?? ''), 'location' => (string) ($x['location'] ?? ''),
        'installation_date' => (string) ($x['installation_date'] ?? ''), 'warranty_until' => (string) ($x['warranty_until'] ?? ''),
        'next_service_at' => (string) ($x['next_service_at'] ?? ''), 'status' => (string) ($x['status'] ?? 'activo'), 'notes' => (string) ($x['notes'] ?? ''),
    ];
};
$emptyEquip = $equipShape([]);
$emptyEquip['client_id'] = '';
$editingClean = $editing ? $equipShape($editing) : null;

$equipStatuses = ['activo', 'requiere revision', 'fuera de servicio', 'retirado'];
$q = trim((string) ($_GET['q'] ?? ''));
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, $equipStatuses, true)) { $statusFilter = ''; }
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$totalPages = 1;
$totalMatching = 0;
$equipmentOverdue = 0;

if ($hasDb) {
    $where = '1=1';
    $params = [];
    if ($clientFilter > 0) { $where .= ' AND equipment.client_id = ?'; $params[] = $clientFilter; }
    if ($statusFilter !== '') { $where .= ' AND equipment.status = ?'; $params[] = $statusFilter; }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where .= ' AND (equipment.name LIKE ? OR equipment.serial LIKE ? OR equipment.brand LIKE ? OR equipment.model LIKE ? OR clients.name LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $ocSel = table_exists('tickets')
        ? "(SELECT COUNT(*) FROM tickets t WHERE t.equipment_id=equipment.id AND t.priority IN ('Critica','Alta') AND t.status NOT IN ('Resuelto','Cerrado'))"
        : '0';
    $totalMatching = (int) (fetch_one("SELECT COUNT(*) total FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE {$where}", $params)['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalMatching / $perPage));
    $equipment = fetch_all("SELECT equipment.*, clients.name AS client_name, {$ocSel} AS open_critical FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE {$where} ORDER BY (equipment.status='requiere revision') DESC, equipment.created_at DESC LIMIT {$perPage} OFFSET {$offset}", $params);
    // DB-wide KPIs (honest regardless of page/filter)
    $equipmentTotal = (int) (fetch_one('SELECT COUNT(*) c FROM equipment')['c'] ?? 0);
    $equipmentClients = (int) (fetch_one('SELECT COUNT(DISTINCT client_id) c FROM equipment')['c'] ?? 0);
    $equipmentAttention = db_count('equipment', "status IN ('requiere revision','fuera de servicio')");
    $equipmentDueSoon = db_count('equipment', "next_service_at IS NOT NULL AND next_service_at >= CURDATE() AND next_service_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $equipmentWarrantySoon = db_count('equipment', "warranty_until IS NOT NULL AND warranty_until >= CURDATE() AND warranty_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $equipmentOverdue = db_count('equipment', "next_service_at IS NOT NULL AND next_service_at < CURDATE() AND status NOT IN ('retirado','fuera de servicio')");
} else {
    $equipment = [
        ['id' => 1, 'client_name' => 'Hospital Metropolitano de Santiago', 'name' => 'Sistema central de gases', 'brand' => 'Precision Medical', 'model' => 'Central O2', 'serial' => 'SCH-HMS-001', 'area' => 'Emergencia', 'status' => 'activo', 'warranty_until' => date('Y-m-d', strtotime('+180 days')), 'next_service_at' => date('Y-m-d', strtotime('+10 days')), 'last_service_at' => date('Y-m-d', strtotime('-170 days')), 'open_critical' => 0],
        ['id' => 2, 'client_name' => 'Plaza de la Salud', 'name' => 'Lampara quirurgica', 'brand' => 'Hill-Rom', 'model' => 'OR', 'serial' => 'SCH-PDS-087', 'area' => 'Quirofano 2', 'status' => 'requiere revision', 'warranty_until' => date('Y-m-d', strtotime('+60 days')), 'next_service_at' => date('Y-m-d', strtotime('-3 days')), 'last_service_at' => date('Y-m-d', strtotime('-200 days')), 'open_critical' => 1],
    ];
    $equipmentTotal = count($equipment);
    $totalMatching = $equipmentTotal;
    $equipmentClients = 2;
    $equipmentAttention = 1;
    $equipmentDueSoon = 0;
    $equipmentWarrantySoon = 1;
    $equipmentOverdue = 1;
}

$equipQueryForPage = fn (int $p) => http_build_query(array_filter([
    'q' => $q, 'client_id' => $clientFilter ?: '', 'status' => $statusFilter, 'page' => $p,
], fn ($v) => $v !== '' && $v !== null));

/** Read-time health badge from objective signals (does not overwrite manual status). */
$healthBadge = function (array $x): string {
    $today = date('Y-m-d');
    if ((int) ($x['open_critical'] ?? 0) > 0) return 'En incidencia';
    if (!empty($x['next_service_at']) && $x['next_service_at'] < $today && !in_array(strtolower((string) ($x['status'] ?? '')), ['retirado', 'fuera de servicio'], true)) return 'Servicio vencido';
    if (!empty($x['warranty_until']) && $x['warranty_until'] < $today) return 'Garantía vencida';
    return '';
};

$crmTitle = 'Equipos';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar equipos.</div>
<?php endif; ?>

<section class="crm-cockpit" x-data="crmFormModal(<?= e(json_encode($emptyEquip)) ?>, <?= $editingClean ? e(json_encode($editingClean)) : 'null' ?>)">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--service">
            <span class="crm-kicker"><i data-lucide="monitor"></i>Inventario técnico</span>
            <h2>Equipos instalados, garantía y próximo servicio sin abrir registros.</h2>
            <p>Controla series, ubicaciones y estados de mantenimiento por cliente. Los equipos con revisión o vencimiento quedan arriba como señales operativas.</p>
            <div class="crm-cockpit__actions">
                <button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus" class="h-4 w-4"></i>Nuevo equipo</button>
                <a href="<?= url('crm/tickets.php') ?>" class="crm-secondary-btn"><i data-lucide="life-buoy" class="h-4 w-4"></i>Ver tickets</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de equipos">
            <article><span>Total</span><strong><?= e((string) $equipmentTotal) ?></strong><small><?= e((string) $equipmentClients) ?> clientes</small></article>
            <article><span>Atención</span><strong><?= e((string) $equipmentAttention) ?></strong><small>revisión o fuera de servicio</small></article>
            <article><span>7 días</span><strong><?= e((string) $equipmentDueSoon) ?></strong><small>servicio próximo</small></article>
            <article><span>Vencidos</span><strong style="<?= $equipmentOverdue > 0 ? 'color:var(--red)' : '' ?>"><?= e((string) $equipmentOverdue) ?></strong><small>servicio atrasado</small></article>
            <article><span>Garantía</span><strong><?= e((string) $equipmentWarrantySoon) ?></strong><small>vence en 30 días</small></article>
        </div>
    </div>

    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div>
                <h3>Inventario instalado</h3>
                <p><?php if ($q !== '' || $clientFilter > 0 || $statusFilter !== ''): ?><?= e((string) $totalMatching) ?> coincidencia<?= $totalMatching === 1 ? '' : 's' ?><?php else: ?>Equipos, series, ubicaciones, garantías y mantenimiento próximo.<?php endif; ?></p>
            </div>
            <form method="get" class="crm-toolbar" style="flex-wrap:wrap;gap:.5rem">
                <div class="crm-search-field" style="flex:1 1 180px"><i data-lucide="search" class="h-4 w-4"></i><input name="q" value="<?= e($q) ?>" placeholder="Equipo, serie o marca" class="crm-input"></div>
                <?php if ($hasDb): ?><select name="client_id" class="crm-select" style="max-width:190px"><option value="">Todos los clientes</option><?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>" <?= $clientFilter === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
                <select name="status" class="crm-select" style="max-width:180px"><option value="">Todos los estados</option><?php foreach ($equipStatuses as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option><?php endforeach; ?></select>
                <button type="submit" class="crm-secondary-btn"><i data-lucide="filter" class="h-4 w-4"></i>Filtrar</button>
                <?php if ($q !== '' || $clientFilter > 0 || $statusFilter !== ''): ?><a href="<?= url('crm/equipos.php') ?>" class="crm-secondary-btn"><i data-lucide="x" class="h-4 w-4"></i>Limpiar</a><?php endif; ?>
            </form>
        </div>
        <div class="crm-table-wrap">
        <table class="crm-table crm-data-table">
            <thead>
                <tr>
                    <th>Equipo</th>
                    <th>Cliente</th>
                    <th>Serie</th>
                    <th>Servicio</th>
                    <th>Estado</th>
                    <th class="text-right">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equipment as $item): $ed = $equipShape($item); ?>
                    <tr>
                        <td>
                            <strong><?= e($item['name']) ?></strong>
                            <p class="mt-1 text-xs text-slate-500"><?= e(($item['brand'] ?: 'Marca no definida') . ' - ' . ($item['model'] ?: 'Modelo no definido')) ?></p>
                        </td>
                        <td><?= e($item['client_name'] ?? 'Sin cliente') ?></td>
                        <td><?= e($item['serial'] ?: 'Sin serie') ?></td>
                        <td>
                            <?= e(date_es($item['next_service_at'] ?? null)) ?>
                            <?php if (!empty($item['last_service_at'])): ?><p class="mt-1 text-xs text-slate-400">Último: <?= e(date_es($item['last_service_at'])) ?></p><?php endif; ?>
                        </td>
                        <td>
                            <span class="status-chip <?= e(status_class($item['status'])) ?>"><?= e(status_label($item['status'])) ?></span>
                            <?php $hb = $healthBadge($item); if ($hb !== ''): ?><span class="status-chip bg-red-50 text-red-700 ring-1 ring-red-200" style="margin-top:.2rem"><?= e($hb) ?></span><?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="crm-row-actions">
                                <button type="button" class="crm-icon-action" title="Editar" @click='openEdit(<?= e(json_encode($ed)) ?>)'><i data-lucide="pencil"></i></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar el equipo <?= e(addslashes($item['name'])) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$equipment): ?>
            <div class="crm-empty"><i data-lucide="monitor" class="h-6 w-6"></i><strong><?= $q !== '' || $clientFilter > 0 || $statusFilter !== '' ? 'Sin coincidencias' : 'Sin equipos registrados' ?></strong><p><?= $q !== '' || $clientFilter > 0 || $statusFilter !== '' ? 'Prueba con otros filtros.' : 'Agrega el primer activo instalado con “Nuevo equipo”.' ?></p></div>
        <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="crm-pager">
                <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : url('crm/equipos.php?' . $equipQueryForPage($page - 1)) ?>"><i data-lucide="chevron-left" class="h-4 w-4"></i>Anterior</a>
                <b><?= e((string) $page) ?> / <?= e((string) $totalPages) ?></b>
                <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : url('crm/equipos.php?' . $equipQueryForPage($page + 1)) ?>">Siguiente<i data-lucide="chevron-right" class="h-4 w-4"></i></a>
            </div>
        <?php endif; ?>
    </article>

    <dialog x-ref="dlg" class="crm-modal crm-modal--wide" @click.self="close()" @cancel.prevent="close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" :value="form.id">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="monitor"></i></span>
                <div class="crm-modal__titles">
                    <h2 x-text="form.id ? 'Editar equipo' : 'Nuevo equipo'">Nuevo equipo</h2>
                    <p>Activo instalado, garantía, área y próximo servicio.</p>
                </div>
                <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body">
                <div class="crm-form-grid">
                    <label class="crm-field"><span class="required">Cliente</span>
                        <select name="client_id" required x-model="form.client_id" class="crm-select">
                            <option value="">Seleccionar</option>
                            <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-field"><span class="required">Equipo o sistema</span><input name="name" required x-model="form.name" class="crm-input"></label>
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Marca</span><input name="brand" x-model="form.brand" class="crm-input"></label>
                    <label class="crm-field"><span>Modelo</span><input name="model" x-model="form.model" class="crm-input"></label>
                    <label class="crm-field"><span>Serie</span><input name="serial" x-model="form.serial" class="crm-input"></label>
                    <label class="crm-field"><span>Área</span><input name="area" x-model="form.area" class="crm-input"></label>
                </div>
                <label class="crm-field"><span>Ubicación exacta</span><input name="location" x-model="form.location" class="crm-input"></label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Instalación</span><input type="date" name="installation_date" x-model="form.installation_date" class="crm-input"></label>
                    <label class="crm-field"><span>Garantía hasta</span><input type="date" name="warranty_until" x-model="form.warranty_until" class="crm-input"></label>
                    <label class="crm-field"><span>Próximo servicio</span><input type="date" name="next_service_at" x-model="form.next_service_at" class="crm-input"></label>
                    <label class="crm-field"><span>Estado</span><select name="status" x-model="form.status" class="crm-select"><?php foreach (['activo','requiere revision','fuera de servicio','retirado'] as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></label>
                </div>
                <label class="crm-field"><span>Notas</span><textarea name="notes" rows="3" x-model="form.notes" class="crm-textarea"></textarea></label>
            </div>
            <footer class="crm-modal__foot">
                <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                <button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i><span x-text="form.id ? 'Guardar cambios' : 'Registrar equipo'">Registrar equipo</span></button>
            </footer>
        </form>
    </dialog>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
