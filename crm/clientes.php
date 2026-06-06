<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
verify_csrf();

$hasDb = db(false) && table_exists('clients');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && isset($_POST['delete_id'])) {
    $did = (int) $_POST['delete_id'];
    if ($did > 0) {
        db()->prepare('DELETE FROM clients WHERE id=?')->execute([$did]);
        log_activity('client', $did, 'cliente_eliminado', null);
        flash('success', 'Cliente eliminado.');
    }
    redirect('crm/clientes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
    $id = (int) ($_POST['id'] ?? 0);
    $payload = [
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['rnc'] ?? '')),
        trim((string) ($_POST['email'] ?? '')),
        trim((string) ($_POST['phone'] ?? '')),
        trim((string) ($_POST['address'] ?? '')),
        trim((string) ($_POST['city'] ?? '')),
        trim((string) ($_POST['sector'] ?? '')),
        trim((string) ($_POST['status'] ?? 'activo')),
    ];

    if ($payload[0] === '') {
        flash('warning', 'El nombre del cliente es obligatorio.');
    } elseif ($id > 0) {
        $stmt = db()->prepare('UPDATE clients SET name=?, rnc=?, email=?, phone=?, address=?, city=?, sector=?, status=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([...$payload, $id]);
        log_activity('client', $id, 'cliente_actualizado', $payload[0]);
        flash('success', 'Cliente actualizado.');
        redirect('crm/clientes.php');
    } else {
        $stmt = db()->prepare('INSERT INTO clients (name, rnc, email, phone, address, city, sector, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute($payload);
        log_activity('client', (int) db()->lastInsertId(), 'cliente_creado', $payload[0]);
        flash('success', 'Cliente creado.');
        redirect('crm/clientes.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para guardar clientes en MySQL.');
}

$q = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $hasDb && $editId > 0 ? fetch_one('SELECT * FROM clients WHERE id=?', [$editId]) : null;

$emptyClient = ['id' => 0, 'name' => '', 'rnc' => '', 'email' => '', 'phone' => '', 'address' => '', 'city' => '', 'sector' => 'Privado', 'status' => 'activo'];
$clientShape = function (array $c): array {
    return [
        'id' => (int) ($c['id'] ?? 0), 'name' => (string) ($c['name'] ?? ''), 'rnc' => (string) ($c['rnc'] ?? ''),
        'email' => (string) ($c['email'] ?? ''), 'phone' => (string) ($c['phone'] ?? ''), 'address' => (string) ($c['address'] ?? ''),
        'city' => (string) ($c['city'] ?? ''), 'sector' => (string) ($c['sector'] ?? 'Privado'), 'status' => (string) ($c['status'] ?? 'activo'),
    ];
};
$editingClean = $editing ? $clientShape($editing) : null;

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;
$totalPages = 1;

if ($hasDb) {
    if ($q !== '') {
        $where = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR rnc LIKE ?)';
        $params = ["%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"];
        $order = 'name ASC';
    } else {
        $where = '1=1';
        $params = [];
        $order = 'created_at DESC';
    }
    $totalMatching = (int) (fetch_one("SELECT COUNT(*) c FROM clients WHERE {$where}", $params)['c'] ?? 0);
    $totalPages = max(1, (int) ceil($totalMatching / $perPage));
    $clients = fetch_all("SELECT * FROM clients WHERE {$where} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}", $params);
    $clientTotal = (int) (fetch_one('SELECT COUNT(*) c FROM clients')['c'] ?? 0);
    $clientActive = db_count('clients', "status='activo'");
    $clientProspects = db_count('clients', "status='prospecto'");
    $clientWithContact = db_count('clients', "(COALESCE(email,'')<>'' OR COALESCE(phone,'')<>'')");
    $clientSectorCount = (int) (fetch_one("SELECT COUNT(DISTINCT NULLIF(sector,'')) c FROM clients")['c'] ?? 0);
} else {
    $clients = [
        ['id' => 1, 'name' => 'Hospital Metropolitano de Santiago', 'rnc' => '101-00000-1', 'email' => 'compras@hms.local', 'phone' => '809-000-0000', 'city' => 'Santiago', 'sector' => 'Privado', 'status' => 'activo', 'created_at' => date('Y-m-d')],
        ['id' => 2, 'name' => 'Plaza de la Salud', 'rnc' => '101-00000-2', 'email' => 'biomedica@plaza.local', 'phone' => '809-000-0001', 'city' => 'Santo Domingo', 'sector' => 'Privado', 'status' => 'activo', 'created_at' => date('Y-m-d')],
        ['id' => 3, 'name' => 'Hospital Jaime Mota', 'rnc' => '101-00000-3', 'email' => 'mantenimiento@jaimemota.local', 'phone' => '809-000-0002', 'city' => 'Barahona', 'sector' => 'Publico', 'status' => 'activo', 'created_at' => date('Y-m-d')],
    ];
    $clientTotal = count($clients);
    $clientActive = count(array_filter($clients, fn ($c) => strtolower((string) ($c['status'] ?? '')) === 'activo'));
    $clientProspects = count(array_filter($clients, fn ($c) => strtolower((string) ($c['status'] ?? '')) === 'prospecto'));
    $clientWithContact = count(array_filter($clients, fn ($c) => trim((string) ($c['email'] ?? '')) !== '' || trim((string) ($c['phone'] ?? '')) !== ''));
    $clientSectorCount = count(array_unique(array_filter(array_map(fn ($c) => trim((string) ($c['sector'] ?? '')), $clients))));
}

$clientQueryForPage = fn (int $p) => http_build_query(array_filter(['q' => $q, 'page' => $p], fn ($v) => $v !== '' && $v !== null));

$crmTitle = 'Clientes';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar clientes.</div>
<?php endif; ?>

<section class="crm-cockpit" x-data="crmFormModal(<?= e(json_encode($emptyClient)) ?>, <?= $editingClean ? e(json_encode($editingClean)) : 'null' ?>)">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero">
            <span class="crm-kicker"><i data-lucide="building-2"></i>Directorio institucional</span>
            <h2>Clientes con contexto comercial y operativo en una sola vista.</h2>
            <p>Ubica instituciones, contactos, sector y estado sin entrar a cada registro. El alta y la edicion siguen en modal para no perder el flujo.</p>
            <div class="crm-cockpit__actions">
                <button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus" class="h-4 w-4"></i>Nuevo cliente</button>
                <a href="<?= url('crm/equipos.php') ?>" class="crm-secondary-btn"><i data-lucide="monitor" class="h-4 w-4"></i>Ver equipos</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de clientes">
            <article><span>Total</span><strong><?= e((string) $clientTotal) ?></strong><small>registros visibles</small></article>
            <article><span>Activos</span><strong><?= e((string) $clientActive) ?></strong><small>operando</small></article>
            <article><span>Prospectos</span><strong><?= e((string) $clientProspects) ?></strong><small>seguimiento comercial</small></article>
            <article><span>Contactables</span><strong><?= e((string) $clientWithContact) ?></strong><small><?= e((string) $clientSectorCount) ?> sectores</small></article>
        </div>
    </div>

    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div>
                <h3>Directorio de clientes</h3>
                <p><?= $q !== '' ? 'Resultados filtrados para "' . e($q) . '".' : 'Instituciones ordenadas por actividad reciente.' ?></p>
            </div>
            <div class="crm-toolbar">
            <form method="get" class="crm-search-field">
                <i data-lucide="search" class="h-4 w-4"></i>
                <input name="q" value="<?= e($q) ?>" placeholder="Buscar cliente" class="crm-input">
            </form>
            <?php if ($q !== ''): ?><a href="<?= url('crm/clientes.php') ?>" class="crm-secondary-btn"><i data-lucide="x" class="h-4 w-4"></i>Limpiar</a><?php endif; ?>
            </div>
        </div>
        <div class="crm-table-wrap">
        <table class="crm-table crm-data-table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Contacto</th>
                    <th>Ubicación</th>
                    <th>Estado</th>
                    <th class="text-right">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $client): $cd = $clientShape($client); ?>
                    <tr>
                        <td>
                            <a href="<?= url('crm/cliente.php?id=' . (int) $client['id']) ?>"><strong><?= e($client['name']) ?></strong></a>
                            <p class="mt-1 text-xs text-slate-500">RNC <?= e($client['rnc'] ?: 'No registrado') ?> - <?= e($client['sector'] ?: 'Sin sector') ?></p>
                        </td>
                        <td>
                            <p><?= e($client['email'] ?: 'Sin correo') ?></p>
                            <p class="mt-1 text-slate-500"><?= e($client['phone'] ?: 'Sin telefono') ?></p>
                        </td>
                        <td><?= e($client['city'] ?: 'Sin ciudad') ?></td>
                        <td><span class="status-chip <?= e(status_class($client['status'])) ?>"><?= e(status_label($client['status'])) ?></span></td>
                        <td class="text-right">
                            <div class="crm-row-actions">
                                <a class="crm-icon-action" href="<?= url('crm/cliente.php?id=' . (int) $client['id']) ?>" title="Ver ficha 360°"><i data-lucide="layout-dashboard"></i></a>
                                <button type="button" class="crm-icon-action" title="Editar" @click='openEdit(<?= e(json_encode($cd)) ?>)'><i data-lucide="pencil"></i></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar a <?= e(addslashes($client['name'])) ?>? También se borrarán sus equipos, cotizaciones y tickets.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_id" value="<?= (int) $client['id'] ?>">
                                    <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$clients): ?>
            <div class="crm-empty"><i data-lucide="building-2" class="h-6 w-6"></i><strong><?= $q !== '' ? 'Sin coincidencias' : 'Aún no hay clientes' ?></strong><p><?= $q !== '' ? 'Prueba con otro término de búsqueda.' : 'Crea tu primera institución con el botón “Nuevo cliente”.' ?></p></div>
        <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="crm-pager">
                <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : url('crm/clientes.php?' . $clientQueryForPage($page - 1)) ?>"><i data-lucide="chevron-left" class="h-4 w-4"></i>Anterior</a>
                <b><?= e((string) $page) ?> / <?= e((string) $totalPages) ?></b>
                <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : url('crm/clientes.php?' . $clientQueryForPage($page + 1)) ?>">Siguiente<i data-lucide="chevron-right" class="h-4 w-4"></i></a>
            </div>
        <?php endif; ?>
    </article>

    <dialog x-ref="dlg" class="crm-modal" @click.self="close()" @cancel.prevent="close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" :value="form.id">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="building-2"></i></span>
                <div class="crm-modal__titles">
                    <h2 x-text="form.id ? 'Editar cliente' : 'Nuevo cliente'">Nuevo cliente</h2>
                    <p x-text="form.id ? 'Actualiza la información institucional.' : 'Registra una institución nueva.'">Registra una institución nueva.</p>
                </div>
                <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body">
                <label class="crm-field"><span class="required">Nombre institucional</span><input name="name" required x-model="form.name" class="crm-input"></label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>RNC</span><input name="rnc" x-model="form.rnc" class="crm-input"></label>
                    <label class="crm-field"><span>Sector</span><select name="sector" x-model="form.sector" class="crm-select"><?php foreach (['Privado','Publico','ONG','Distribuidor'] as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></label>
                </div>
                <label class="crm-field"><span>Correo</span><input type="email" name="email" x-model="form.email" class="crm-input"></label>
                <label class="crm-field"><span>Teléfono</span><input name="phone" x-model="form.phone" class="crm-input"></label>
                <label class="crm-field"><span>Dirección</span><textarea name="address" rows="3" x-model="form.address" class="crm-textarea"></textarea></label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Ciudad</span><input name="city" x-model="form.city" class="crm-input"></label>
                    <label class="crm-field"><span>Estado</span><select name="status" x-model="form.status" class="crm-select"><?php foreach (['activo','inactivo','prospecto'] as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></label>
                </div>
            </div>
            <footer class="crm-modal__foot">
                <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                <button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i><span x-text="form.id ? 'Guardar cambios' : 'Crear cliente'">Crear cliente</span></button>
            </footer>
        </form>
    </dialog>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
