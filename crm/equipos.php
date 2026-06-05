<?php
require_once __DIR__ . '/../includes/bootstrap.php';
verify_csrf();

$hasDb = db(false) && table_exists('equipment');
$clients = $hasDb ? fetch_all('SELECT id, name FROM clients ORDER BY name ASC') : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && isset($_POST['delete_id'])) {
    $did = (int) $_POST['delete_id'];
    if ($did > 0) {
        db()->prepare('DELETE FROM equipment WHERE id=?')->execute([$did]);
        flash('success', 'Equipo eliminado.');
    }
    redirect('crm/equipos.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
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
        flash('success', 'Equipo actualizado.');
        redirect('crm/equipos.php');
    } else {
        $stmt = db()->prepare('INSERT INTO equipment (client_id, name, brand, model, serial, area, location, installation_date, warranty_until, status, next_service_at, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute($payload);
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
$equipment = $hasDb
    ? fetch_all('SELECT equipment.*, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id ORDER BY equipment.created_at DESC LIMIT 120')
    : [
        ['id' => 1, 'client_name' => 'Hospital Metropolitano de Santiago', 'name' => 'Sistema central de gases', 'brand' => 'Precision Medical', 'model' => 'Central O2', 'serial' => 'SCH-HMS-001', 'area' => 'Emergencia', 'status' => 'activo', 'warranty_until' => date('Y-m-d', strtotime('+180 days')), 'next_service_at' => date('Y-m-d', strtotime('+10 days'))],
        ['id' => 2, 'client_name' => 'Plaza de la Salud', 'name' => 'Lampara quirurgica', 'brand' => 'Hill-Rom', 'model' => 'OR', 'serial' => 'SCH-PDS-087', 'area' => 'Quirofano 2', 'status' => 'requiere revision', 'warranty_until' => date('Y-m-d', strtotime('+60 days')), 'next_service_at' => date('Y-m-d', strtotime('+3 days'))],
    ];

$todayTs = strtotime(date('Y-m-d'));
$serviceSoonTs = strtotime('+7 days');
$warrantySoonTs = strtotime('+30 days');
$equipmentTotal = count($equipment);
$equipmentAttention = count(array_filter($equipment, fn ($x) => in_array(strtolower((string) ($x['status'] ?? '')), ['requiere revision', 'fuera de servicio'], true)));
$equipmentDueSoon = count(array_filter($equipment, function ($x) use ($todayTs, $serviceSoonTs) {
    $ts = !empty($x['next_service_at']) ? strtotime((string) $x['next_service_at']) : false;
    return $ts !== false && $ts >= $todayTs && $ts <= $serviceSoonTs;
}));
$equipmentWarrantySoon = count(array_filter($equipment, function ($x) use ($todayTs, $warrantySoonTs) {
    $ts = !empty($x['warranty_until']) ? strtotime((string) $x['warranty_until']) : false;
    return $ts !== false && $ts >= $todayTs && $ts <= $warrantySoonTs;
}));
$equipmentClients = count(array_unique(array_filter(array_map(fn ($x) => trim((string) ($x['client_name'] ?? '')), $equipment))));

$crmTitle = 'Equipos';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar equipos.</div>
<?php endif; ?>

<section class="crm-cockpit" x-data="crmFormModal(<?= e(json_encode($emptyEquip)) ?>, <?= $editingClean ? e(json_encode($editingClean)) : 'null' ?>)">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--service">
            <span class="crm-kicker"><i data-lucide="monitor"></i>Inventario tecnico</span>
            <h2>Equipos instalados, garantia y proximo servicio sin abrir registros.</h2>
            <p>Controla series, ubicaciones y estados de mantenimiento por cliente. Los equipos con revision o vencimiento quedan arriba como senales operativas.</p>
            <div class="crm-cockpit__actions">
                <button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus" class="h-4 w-4"></i>Nuevo equipo</button>
                <a href="<?= url('crm/tickets.php') ?>" class="crm-secondary-btn"><i data-lucide="life-buoy" class="h-4 w-4"></i>Ver tickets</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de equipos">
            <article><span>Total</span><strong><?= e((string) $equipmentTotal) ?></strong><small><?= e((string) $equipmentClients) ?> clientes</small></article>
            <article><span>Atencion</span><strong><?= e((string) $equipmentAttention) ?></strong><small>revision o fuera de servicio</small></article>
            <article><span>7 dias</span><strong><?= e((string) $equipmentDueSoon) ?></strong><small>servicio proximo</small></article>
            <article><span>Garantia</span><strong><?= e((string) $equipmentWarrantySoon) ?></strong><small>vence en 30 dias</small></article>
        </div>
    </div>

    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div>
                <h3>Inventario instalado</h3>
                <p>Equipos, series, ubicaciones, garantias y mantenimiento proximo.</p>
            </div>
            <div class="crm-toolbar">
                <span class="ops-status bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200"><?= e((string) $equipmentTotal) ?> registrados</span>
            </div>
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
                    <th class="text-right">Accion</th>
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
                        <td><?= e(date_es($item['next_service_at'] ?? null)) ?></td>
                        <td><span class="status-chip <?= e(status_class($item['status'])) ?>"><?= e($item['status']) ?></span></td>
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
            <div class="crm-empty"><i data-lucide="monitor" class="h-6 w-6"></i><strong>Sin equipos registrados</strong><p>Agrega el primer activo instalado con “Nuevo equipo”.</p></div>
        <?php endif; ?>
        </div>
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
