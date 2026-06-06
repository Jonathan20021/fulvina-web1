<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('leads.view');
verify_csrf();

$hasDb = db(false) && table_exists('leads');
$leadStatuses = ['nuevo', 'contactado', 'convertido', 'descartado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
    $form = (string) ($_POST['form'] ?? '');

    if ($form === 'status') {
        if (!current_can('leads.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/leads.php'); }
        $lid = (int) ($_POST['id'] ?? 0);
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        if ($lid > 0 && in_array($newStatus, $leadStatuses, true)) {
            db()->prepare('UPDATE leads SET status=? WHERE id=?')->execute([$newStatus, $lid]);
            log_activity('lead', $lid, 'lead_' . $newStatus, null);
            flash('success', 'Estado del lead actualizado.');
        }
        redirect('crm/leads.php');
    }

    if ($form === 'delete') {
        if (!current_can('leads.delete')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/leads.php'); }
        $lid = (int) ($_POST['id'] ?? 0);
        if ($lid > 0) {
            db()->prepare('DELETE FROM leads WHERE id=?')->execute([$lid]);
            log_activity('lead', $lid, 'lead_eliminado', null);
            flash('success', 'Lead eliminado.');
        }
        redirect('crm/leads.php');
    }

    if ($form === 'convert' && table_exists('clients')) {
        if (!current_can('leads.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/leads.php'); }
        $lid = (int) ($_POST['id'] ?? 0);
        $lead = $lid > 0 ? fetch_one('SELECT * FROM leads WHERE id=?', [$lid]) : null;
        if ($lead) {
            $name = trim((string) ($lead['company'] ?: $lead['name']));
            if ($name === '') { $name = 'Cliente sin nombre'; }
            db()->prepare('INSERT INTO clients (name, rnc, email, phone, address, city, sector, status, created_at, updated_at) VALUES (?, "", ?, ?, "", "", ?, "activo", NOW(), NOW())')
                ->execute([$name, (string) ($lead['email'] ?? ''), (string) ($lead['phone'] ?? ''), (string) ($lead['type'] ?? '')]);
            $newId = (int) db()->lastInsertId();
            db()->prepare('UPDATE leads SET status="convertido" WHERE id=?')->execute([$lid]);
            log_activity('client', $newId, 'cliente_desde_lead', $name);
            flash('success', 'Lead convertido en cliente.');
            redirect('crm/cliente.php?id=' . $newId);
        }
        redirect('crm/leads.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para gestionar leads en MySQL.');
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, $leadStatuses, true)) { $statusFilter = ''; }

if ($hasDb) {
    $where = '1=1';
    $params = [];
    if ($statusFilter !== '') { $where .= ' AND status = ?'; $params[] = $statusFilter; }
    $leads = fetch_all("SELECT * FROM leads WHERE {$where} ORDER BY FIELD(status,'nuevo','contactado','convertido','descartado'), created_at DESC LIMIT 200", $params);
    $counts = [
        'total' => (int) (fetch_one('SELECT COUNT(*) c FROM leads')['c'] ?? 0),
        'nuevo' => db_count('leads', "status='nuevo'"),
        'contactado' => db_count('leads', "status='contactado'"),
        'convertido' => db_count('leads', "status='convertido'"),
    ];
} else {
    $leads = [
        ['id' => 1, 'name' => 'Dra. Ana Rivas', 'company' => 'Clínica Unión Médica', 'email' => 'compras@union.local', 'phone' => '809-555-1100', 'type' => 'Equipos médicos', 'message' => 'Solicitud de cotización para 4 monitores de signos vitales.', 'status' => 'nuevo', 'created_at' => date('Y-m-d')],
        ['id' => 2, 'name' => 'Ing. Pedro Gil', 'company' => 'Hospital Regional', 'email' => 'biomedica@hr.local', 'phone' => '809-555-2200', 'type' => 'Gases medicinales', 'message' => 'Mantenimiento de central de gases.', 'status' => 'contactado', 'created_at' => date('Y-m-d', strtotime('-2 days'))],
    ];
    $counts = ['total' => 2, 'nuevo' => 1, 'contactado' => 1, 'convertido' => 0];
}

$crmTitle = 'Leads';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para gestionar leads.</div>
<?php endif; ?>

<section class="crm-cockpit">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero">
            <span class="crm-kicker"><i data-lucide="inbox"></i>Prospección comercial</span>
            <h2>Solicitudes del sitio público listas para convertir.</h2>
            <p>Da seguimiento a cada lead, cámbialo de estado y conviértelo en cliente con un clic para iniciar su ficha y cotizaciones.</p>
            <div class="crm-cockpit__actions">
                <a href="<?= url('crm/clientes.php') ?>" class="crm-secondary-btn"><i data-lucide="building-2" class="h-4 w-4"></i>Clientes</a>
                <a href="<?= url('contacto.php') ?>" target="_blank" rel="noopener" class="crm-secondary-btn"><i data-lucide="external-link" class="h-4 w-4"></i>Formulario público</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de leads">
            <article><span>Total</span><strong><?= e((string) $counts['total']) ?></strong><small>recibidos</small></article>
            <article><span>Nuevos</span><strong><?= e((string) $counts['nuevo']) ?></strong><small>sin contactar</small></article>
            <article><span>Contactados</span><strong><?= e((string) $counts['contactado']) ?></strong><small>en seguimiento</small></article>
            <article><span>Convertidos</span><strong><?= e((string) $counts['convertido']) ?></strong><small>ahora clientes</small></article>
        </div>
    </div>

    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div>
                <h3>Bandeja de leads</h3>
                <p>Solicitudes recibidas desde el sitio público.</p>
            </div>
            <form method="get" class="crm-toolbar">
                <select name="status" class="crm-select" onchange="this.form.submit()" style="max-width:180px">
                    <option value="">Todos los estados</option>
                    <?php foreach ($leadStatuses as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option><?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="crm-table-wrap">
            <table class="crm-table crm-data-table">
                <thead>
                    <tr>
                        <th>Contacto</th>
                        <th>Tipo / mensaje</th>
                        <th>Estado</th>
                        <th class="text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td>
                                <strong><?= e($lead['name']) ?></strong>
                                <p class="mt-1 text-xs text-slate-500"><?= e($lead['company'] ?: 'Sin empresa') ?></p>
                                <p class="text-xs text-slate-500"><?= e($lead['email']) ?><?= !empty($lead['phone']) ? ' · ' . e($lead['phone']) : '' ?></p>
                            </td>
                            <td>
                                <?php if (!empty($lead['type'])): ?><span class="quote-cat"><i data-lucide="tag"></i><?= e($lead['type']) ?></span><br><?php endif; ?>
                                <span class="text-xs text-slate-600"><?= e(mb_strimwidth((string) $lead['message'], 0, 110, '…')) ?></span>
                            </td>
                            <td>
                                <?php if ($hasDb): ?>
                                    <form method="post" class="quote-status-form" onchange="this.submit()">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="form" value="status">
                                        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                        <select name="status" aria-label="Cambiar estado del lead">
                                            <?php foreach ($leadStatuses as $st): ?><option value="<?= e($st) ?>" <?= (string) $lead['status'] === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option><?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <span class="status-chip <?= e(status_class($lead['status'])) ?>"><?= e(status_label($lead['status'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div class="crm-row-actions">
                                    <?php if ($hasDb && (string) $lead['status'] !== 'convertido'): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('¿Convertir este lead en un cliente nuevo?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="form" value="convert">
                                            <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                            <button type="submit" class="crm-secondary-btn" style="height:32px;padding:0 .6rem"><i data-lucide="user-round-plus" class="h-4 w-4"></i>Convertir</button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="crm-icon-action" href="mailto:<?= e($lead['email']) ?>" title="Responder por correo"><i data-lucide="mail"></i></a>
                                    <?php if ($hasDb): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este lead?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="form" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                            <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$leads): ?>
                <div class="crm-empty"><i data-lucide="inbox" class="h-6 w-6"></i><strong><?= $statusFilter !== '' ? 'Sin leads en este estado' : 'No hay leads' ?></strong><p>Las solicitudes del formulario público aparecerán aquí.</p></div>
            <?php endif; ?>
        </div>
    </article>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
