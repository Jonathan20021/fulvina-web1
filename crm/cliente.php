<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('clientes.view');
verify_csrf();
if (db(false)) { ensure_quote_schema(); ensure_helpdesk_schema(); }

$hasDb = db(false) && table_exists('clients');
$id = (int) ($_GET['id'] ?? 0);

$client = $hasDb && $id > 0 ? fetch_one('SELECT * FROM clients WHERE id=?', [$id]) : null;

if ($hasDb && !$client) {
    flash('warning', 'El cliente solicitado no existe.');
    redirect('crm/clientes.php');
}

/* ---- Contacts CRUD (scoped to this client) ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && $client && table_exists('contacts')) {
    $cform = (string) ($_POST['contact_form'] ?? '');
    if ($cform === 'delete') {
        if (!current_can('clientes.delete')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/cliente.php?id=' . $id); }
        $cid = (int) ($_POST['contact_id'] ?? 0);
        if ($cid > 0) {
            db()->prepare('DELETE FROM contacts WHERE id=? AND client_id=?')->execute([$cid, $id]);
            flash('success', 'Contacto eliminado.');
        }
        redirect('crm/cliente.php?id=' . $id);
    }
    if ($cform === 'add' || $cform === 'edit') {
        if (!current_can('clientes.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/cliente.php?id=' . $id); }
        $cid = (int) ($_POST['contact_id'] ?? 0);
        $cname = trim((string) ($_POST['contact_name'] ?? ''));
        $cemail = trim((string) ($_POST['contact_email'] ?? ''));
        $cphone = trim((string) ($_POST['contact_phone'] ?? ''));
        $cposition = trim((string) ($_POST['contact_position'] ?? ''));
        $cprimary = isset($_POST['contact_is_primary']) ? 1 : 0;
        if ($cname === '') {
            flash('warning', 'El nombre del contacto es obligatorio.');
        } else {
            if ($cprimary) {
                db()->prepare('UPDATE contacts SET is_primary=0 WHERE client_id=?')->execute([$id]);
            }
            if ($cform === 'edit' && $cid > 0) {
                db()->prepare('UPDATE contacts SET name=?, email=?, phone=?, position=?, is_primary=? WHERE id=? AND client_id=?')
                    ->execute([$cname, $cemail, $cphone, $cposition, $cprimary, $cid, $id]);
            } else {
                db()->prepare('INSERT INTO contacts (client_id, name, email, phone, position, is_primary, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$id, $cname, $cemail, $cphone, $cposition, $cprimary]);
            }
            log_activity('client', $id, 'contacto_guardado', $cname);
            flash('success', 'Contacto guardado.');
        }
        redirect('crm/cliente.php?id=' . $id);
    }
}

$demo = !$hasDb;
if ($demo) {
    $client = ['id' => 0, 'name' => 'Hospital Metropolitano de Santiago', 'rnc' => '101-00000-1', 'email' => 'compras@hms.local', 'phone' => '809-000-0000', 'address' => 'Av. Juan Pablo Duarte, Santiago', 'city' => 'Santiago', 'sector' => 'Privado', 'status' => 'activo', 'created_at' => date('Y-m-d', strtotime('-2 years'))];
    $equipment = [
        ['id' => 1, 'name' => 'Tomógrafo Siemens Somatom', 'brand' => 'Siemens', 'serial' => '12345ABC', 'area' => 'Imagenología', 'status' => 'activo', 'warranty_until' => date('Y-m-d', strtotime('+58 days')), 'next_service_at' => date('Y-m-d', strtotime('+10 days'))],
        ['id' => 2, 'name' => 'Central de gases medicinales', 'brand' => 'Precision Medical', 'serial' => 'SCH-HMS-001', 'area' => 'Emergencia', 'status' => 'requiere revision', 'warranty_until' => date('Y-m-d', strtotime('+180 days')), 'next_service_at' => date('Y-m-d', strtotime('+3 days'))],
    ];
    $quotes = [
        ['id' => 1, 'quote_number' => 'SCH-2026-0042', 'title' => 'Renovación de monitores UCI', 'category' => 'Equipos médicos', 'status' => 'Negociacion', 'total' => 486200, 'created_at' => date('Y-m-d', strtotime('-5 days'))],
        ['id' => 2, 'quote_number' => 'SCH-2026-0031', 'title' => 'Mantenimiento anual', 'category' => 'Soporte y mantenimiento', 'status' => 'Aprobado', 'total' => 96500, 'created_at' => date('Y-m-d', strtotime('-40 days'))],
    ];
    $tickets = [
        ['id' => 267, 'subject' => 'Tomógrafo intermitente, error 8042', 'priority' => 'Alta', 'status' => 'Abierto', 'eqname' => 'Tomógrafo Siemens', 'created_at' => date('Y-m-d', strtotime('-1 day'))],
    ];
    $contacts = [['name' => 'Ing. Laura Peña', 'position' => 'Jefa de Biomédica', 'email' => 'biomedica@hms.local', 'phone' => '809-555-2266', 'is_primary' => 1]];
} else {
    $equipment = fetch_all('SELECT * FROM equipment WHERE client_id=? ORDER BY (status="requiere revision") DESC, next_service_at IS NULL, next_service_at ASC LIMIT 50', [$id]);
    $quotes = fetch_all('SELECT * FROM quotes WHERE client_id=? ORDER BY created_at DESC LIMIT 50', [$id]);
    $tickets = table_exists('tickets')
        ? fetch_all('SELECT tickets.*, equipment.name AS eqname FROM tickets LEFT JOIN equipment ON equipment.id = tickets.equipment_id WHERE tickets.client_id=? ORDER BY tickets.created_at DESC LIMIT 50', [$id])
        : [];
    $contacts = table_exists('contacts') ? fetch_all('SELECT * FROM contacts WHERE client_id=? ORDER BY is_primary DESC, name ASC', [$id]) : [];
}

/* ---- Stats -------------------------------------------------------------- */
$openStates = analytics_open_states();
$pipelineValue = array_sum(array_map(fn ($q) => in_array((string) $q['status'], $openStates, true) ? (float) $q['total'] : 0, $quotes));
$wonValue = array_sum(array_map(fn ($q) => (string) $q['status'] === 'Aprobado' ? (float) $q['total'] : 0, $quotes));
$openTickets = count(array_filter($tickets, fn ($t) => !in_array((string) $t['status'], ['Resuelto', 'Cerrado'], true)));
$warrantySoon = count(array_filter($equipment, function ($e) {
    $ts = !empty($e['warranty_until']) ? strtotime((string) $e['warranty_until']) : false;
    return $ts !== false && $ts >= time() && $ts <= strtotime('+90 days');
}));

$initials = strtoupper(mb_substr(preg_replace('/^(Hospital|Centro|Clínica|Clinica)\s+/iu', '', trim((string) $client['name'])), 0, 2));

/* ---- Activity timeline (quotes + tickets merged) ------------------------ */
$timeline = [];
foreach ($quotes as $q) {
    $timeline[] = ['ts' => strtotime((string) ($q['created_at'] ?? 'now')), 'icon' => 'file-text', 'color' => '#0a7d36', 'title' => 'Cotización ' . ($q['quote_number'] ?? '') . ' · ' . money($q['total'] ?? 0), 'sub' => ($q['title'] ?? '') . ' · ' . ($q['status'] ?? ''), 'href' => url('crm/cotizaciones.php?action=view&id=' . (int) ($q['id'] ?? 0))];
}
foreach ($tickets as $t) {
    $timeline[] = ['ts' => strtotime((string) ($t['created_at'] ?? 'now')), 'icon' => 'life-buoy', 'color' => '#0666b3', 'title' => 'Ticket TK-' . date('Y', strtotime((string) ($t['created_at'] ?? 'now'))) . '-' . str_pad((string) ($t['id'] ?? 0), 4, '0', STR_PAD_LEFT) . ' · ' . ($t['priority'] ?? ''), 'sub' => ($t['subject'] ?? '') . ' · ' . ($t['status'] ?? ''), 'href' => url('crm/tickets.php?id=' . (int) ($t['id'] ?? 0))];
}
usort($timeline, fn ($a, $b) => $b['ts'] <=> $a['ts']);
$timeline = array_slice($timeline, 0, 12);

$portalUrl = '';
if (!$demo && column_exists('clients', 'support_slug')) {
    $portalUrl = client_support_url($client);
}

$crmTitle = $client['name'];
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if ($demo): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Vista de demostración. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para ver fichas reales de clientes.</div>
<?php endif; ?>

<div class="mb-3 print:hidden">
    <a href="<?= url('crm/clientes.php') ?>" class="crm-secondary-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i>Volver a clientes</a>
</div>

<!-- Header -->
<div class="c360-head">
    <span class="c360-head__avatar"><?= e($initials) ?></span>
    <div class="c360-head__id">
        <h2><?= e($client['name']) ?></h2>
        <p><?= e(($client['sector'] ?: 'Sin sector') . ' · ' . ($client['city'] ?: 'Sin ciudad')) ?> · <span class="status-chip <?= e(status_class($client['status'])) ?>"><?= e(status_label($client['status'])) ?></span></p>
        <div class="c360-head__meta">
            <?php if (!empty($client['rnc'])): ?><span><i data-lucide="hash"></i>RNC <?= e($client['rnc']) ?></span><?php endif; ?>
            <?php if (!empty($client['email'])): ?><span><i data-lucide="mail"></i><?= e($client['email']) ?></span><?php endif; ?>
            <?php if (!empty($client['phone'])): ?><span><i data-lucide="phone"></i><?= e($client['phone']) ?></span><?php endif; ?>
            <?php if (!empty($client['address'])): ?><span><i data-lucide="map-pin"></i><?= e($client['address']) ?></span><?php endif; ?>
            <span><i data-lucide="calendar"></i>Cliente desde <?= e(date_es($client['created_at'] ?? null)) ?></span>
        </div>
    </div>
    <div class="c360-head__actions">
        <?php if (!$demo): ?>
            <a href="<?= url('crm/clientes.php?edit=' . (int) $client['id']) ?>" class="crm-secondary-btn"><i data-lucide="pencil" class="h-4 w-4"></i>Editar</a>
            <a href="<?= url('crm/cotizaciones.php?new=1') ?>" class="crm-secondary-btn"><i data-lucide="file-plus-2" class="h-4 w-4"></i>Cotización</a>
            <a href="<?= url('crm/tickets.php?new=1') ?>" class="crm-primary-btn"><i data-lucide="life-buoy" class="h-4 w-4"></i>Ticket</a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats -->
<div class="c360-stats">
    <div class="c360-stat"><span>Pipeline activo</span><strong><?= e('RD$ ' . number_format($pipelineValue, 0, '.', ',')) ?></strong><small><?= e((string) count($quotes)) ?> cotizaciones</small></div>
    <div class="c360-stat"><span>Ganado histórico</span><strong><?= e('RD$ ' . number_format($wonValue, 0, '.', ',')) ?></strong><small>aprobadas</small></div>
    <div class="c360-stat"><span>Equipos</span><strong><?= e((string) count($equipment)) ?></strong><small><?= e((string) $warrantySoon) ?> garantía &lt; 90d</small></div>
    <div class="c360-stat"><span>Tickets abiertos</span><strong><?= e((string) $openTickets) ?></strong><small><?= e((string) count($tickets)) ?> en total</small></div>
</div>

<div class="c360-grid">
    <!-- Left column -->
    <div style="display:grid;gap:1rem;align-content:start">
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="monitor"></i> Equipos instalados</h3><a class="dash-card__meta" href="<?= url('crm/equipos.php') ?>">Inventario</a></div>
            <?php if ($equipment): ?>
                <div class="crm-table-wrap" style="padding:0 .4rem .6rem">
                    <table class="crm-table">
                        <thead><tr><th>Equipo</th><th>Serie</th><th>Próx. servicio</th><th>Estado</th></tr></thead>
                        <tbody>
                            <?php foreach ($equipment as $eq): ?>
                                <tr>
                                    <td><a href="<?= url('crm/equipos.php?edit=' . (int) ($eq['id'] ?? 0)) ?>"><strong><?= e($eq['name']) ?></strong></a><p class="text-xs text-slate-500"><?= e(($eq['brand'] ?: 'Marca n/d') . ' · ' . ($eq['area'] ?: 'Área n/d')) ?></p></td>
                                    <td><?= e($eq['serial'] ?: 'Sin serie') ?></td>
                                    <td class="ops-nowrap"><?= e(date_es($eq['next_service_at'] ?? null)) ?></td>
                                    <td><span class="status-chip <?= e(status_class($eq['status'])) ?>"><?= e(status_label($eq['status'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="monitor"></i><strong>Sin equipos registrados</strong><p>Registra los activos instalados de este cliente.</p></div>
            <?php endif; ?>
        </article>

        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="file-text"></i> Cotizaciones</h3><a class="dash-card__meta" href="<?= url('crm/cotizaciones.php') ?>">Todas</a></div>
            <?php if ($quotes): ?>
                <div class="crm-table-wrap" style="padding:0 .4rem .6rem">
                    <table class="crm-table">
                        <thead><tr><th>Número</th><th>Título</th><th>Estado</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($quotes as $q): ?>
                                <tr>
                                    <td><a href="<?= url('crm/cotizaciones.php?action=view&id=' . (int) $q['id']) ?>"><strong><?= e($q['quote_number']) ?></strong></a></td>
                                    <td><?= e($q['title']) ?></td>
                                    <td><span class="status-chip <?= e(status_class($q['status'])) ?>"><?= e($q['status']) ?></span></td>
                                    <td class="text-right"><strong><?= money($q['total']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="file-text"></i><strong>Sin cotizaciones</strong><p>Crea la primera propuesta para este cliente.</p></div>
            <?php endif; ?>
        </article>

        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="life-buoy"></i> Tickets de soporte</h3><a class="dash-card__meta" href="<?= url('crm/tickets.php') ?>">Helpdesk</a></div>
            <?php if ($tickets): ?>
                <div class="crm-table-wrap" style="padding:0 .4rem .6rem">
                    <table class="crm-table">
                        <thead><tr><th>Ticket</th><th>Equipo</th><th>Prioridad</th><th>Estado</th></tr></thead>
                        <tbody>
                            <?php foreach ($tickets as $t): ?>
                                <tr>
                                    <td><a href="<?= url('crm/tickets.php?id=' . (int) $t['id']) ?>"><strong>TK-<?= e(date('Y', strtotime((string) ($t['created_at'] ?? 'now')))) ?>-<?= e(str_pad((string) $t['id'], 4, '0', STR_PAD_LEFT)) ?></strong></a><p class="text-xs text-slate-500"><?= e($t['subject']) ?></p></td>
                                    <td><?= e($t['eqname'] ?? 'Sin equipo') ?></td>
                                    <td><span class="status-chip <?= e(priority_class($t['priority'])) ?>"><?= e($t['priority']) ?></span></td>
                                    <td><span class="status-chip <?= e(status_class($t['status'])) ?>"><?= e($t['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="life-buoy"></i><strong>Sin tickets</strong><p>No hay casos de soporte registrados para este cliente.</p></div>
            <?php endif; ?>
        </article>
    </div>

    <!-- Right column -->
    <div style="display:grid;gap:1rem;align-content:start">
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="history"></i> Actividad reciente</h3></div>
            <?php if ($timeline): ?>
                <div class="c360-timeline">
                    <?php foreach ($timeline as $ev): ?>
                        <a class="c360-timeline__row" href="<?= e($ev['href']) ?>">
                            <span class="c360-timeline__dot" style="background:<?= e($ev['color']) ?>"></span>
                            <div class="c360-timeline__body">
                                <b><?= e($ev['title']) ?></b>
                                <span><?= e($ev['sub']) ?></span>
                                <time><?= e(date_es(date('Y-m-d', $ev['ts']))) ?></time>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="history"></i><strong>Sin actividad</strong><p>Las cotizaciones y tickets aparecerán aquí.</p></div>
            <?php endif; ?>
        </article>

        <article class="dash-card" x-data="crmFormModal({contact_id:0,contact_name:'',contact_email:'',contact_phone:'',contact_position:'',contact_is_primary:false})">
            <div class="dash-card__head">
                <h3><i data-lucide="contact"></i> Contactos</h3>
                <?php if (!$demo): ?><button type="button" class="crm-icon-action" title="Agregar contacto" @click="openNew()"><i data-lucide="plus"></i></button><?php endif; ?>
            </div>
            <div class="dash-card__body" style="display:grid;gap:.6rem">
                <?php foreach ($contacts as $c): ?>
                    <div style="display:flex;gap:.6rem;align-items:flex-start">
                        <span class="av av--blue" style="--av-size:34px"><?= e(strtoupper(mb_substr((string) $c['name'], 0, 1))) ?></span>
                        <div style="min-width:0;flex:1">
                            <b style="font-size:.86rem;color:var(--ink)"><?= e($c['name']) ?><?= !empty($c['is_primary']) ? ' · <span style="color:var(--brand-strong);font-size:.72rem">Principal</span>' : '' ?></b>
                            <p class="text-xs text-slate-500"><?= e($c['position'] ?: 'Contacto') ?></p>
                            <p class="text-xs text-slate-600"><?= e($c['email'] ?: '') ?><?= !empty($c['phone']) ? ' · ' . e($c['phone']) : '' ?></p>
                        </div>
                        <?php if (!$demo && !empty($c['id'])): ?>
                            <div class="crm-row-actions">
                                <button type="button" class="crm-icon-action" title="Editar" @click='openEdit(<?= e(json_encode(['contact_id' => (int) $c['id'], 'contact_name' => (string) $c['name'], 'contact_email' => (string) ($c['email'] ?? ''), 'contact_phone' => (string) ($c['phone'] ?? ''), 'contact_position' => (string) ($c['position'] ?? ''), 'contact_is_primary' => !empty($c['is_primary'])])) ?>)'><i data-lucide="pencil"></i></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este contacto?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="contact_form" value="delete">
                                    <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$contacts): ?>
                    <div class="chart-empty" style="min-height:120px"><i data-lucide="contact"></i><strong>Sin contactos</strong><p><?= $demo ? 'Usa el correo y teléfono institucional de la ficha.' : 'Agrega el primer contacto con el botón +.' ?></p></div>
                <?php endif; ?>
            </div>

            <?php if (!$demo): ?>
            <dialog x-ref="dlg" class="crm-modal" @click.self="close()" @cancel.prevent="close()">
                <form method="post" class="crm-modal__form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="contact_form" :value="form.contact_id ? 'edit' : 'add'">
                    <input type="hidden" name="contact_id" :value="form.contact_id">
                    <header class="crm-modal__head">
                        <span class="crm-modal__icon"><i data-lucide="contact"></i></span>
                        <div class="crm-modal__titles">
                            <h2 x-text="form.contact_id ? 'Editar contacto' : 'Nuevo contacto'">Nuevo contacto</h2>
                            <p>Persona de contacto de <?= e($client['name']) ?>.</p>
                        </div>
                        <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
                    </header>
                    <div class="crm-modal__body">
                        <label class="crm-field"><span class="required">Nombre</span><input name="contact_name" required x-model="form.contact_name" class="crm-input"></label>
                        <label class="crm-field"><span>Cargo / posición</span><input name="contact_position" x-model="form.contact_position" class="crm-input" placeholder="Ej. Jefa de Biomédica"></label>
                        <div class="crm-form-grid">
                            <label class="crm-field"><span>Correo</span><input type="email" name="contact_email" x-model="form.contact_email" class="crm-input"></label>
                            <label class="crm-field"><span>Teléfono</span><input name="contact_phone" x-model="form.contact_phone" class="crm-input"></label>
                        </div>
                        <label class="helpdesk-note-check" style="display:flex;align-items:center;gap:.4rem"><input type="checkbox" name="contact_is_primary" x-model="form.contact_is_primary"> Contacto principal</label>
                    </div>
                    <footer class="crm-modal__foot">
                        <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                        <button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i><span x-text="form.contact_id ? 'Guardar' : 'Agregar'">Agregar</span></button>
                    </footer>
                </form>
            </dialog>
            <?php endif; ?>
        </article>

        <?php if ($portalUrl): ?>
            <article class="dash-card">
                <div class="dash-card__head"><h3><i data-lucide="link-2"></i> Portal de soporte</h3></div>
                <div class="dash-card__body" style="display:grid;gap:.5rem">
                    <p class="text-xs text-slate-500">Link público para que el cliente reporte tickets.</p>
                    <input class="crm-input" readonly value="<?= e($portalUrl) ?>" style="font-size:.74rem">
                    <div style="display:flex;gap:.5rem">
                        <button type="button" class="crm-secondary-btn" data-copy="<?= e($portalUrl) ?>"><i data-lucide="copy" class="h-4 w-4"></i>Copiar</button>
                        <a href="<?= e($portalUrl) ?>" target="_blank" rel="noopener" class="crm-secondary-btn"><i data-lucide="external-link" class="h-4 w-4"></i>Abrir</a>
                    </div>
                </div>
            </article>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
