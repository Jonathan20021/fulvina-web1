<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('tickets.view');
verify_csrf();
ensure_helpdesk_schema();

$hasDb = db(false) && table_exists('tickets');
$hasPortalColumns = $hasDb && column_exists('clients', 'support_slug');
$clients = $hasDb ? fetch_all('SELECT id, name, email, phone, city, sector, status, support_slug, support_token, support_enabled FROM clients ORDER BY name ASC') : [];
$equipmentList = $hasDb ? fetch_all('SELECT equipment.id, equipment.name, equipment.serial, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id ORDER BY clients.name, equipment.name') : [];
$users = $hasDb && table_exists('users') ? fetch_all('SELECT id, name FROM users WHERE status="activo" ORDER BY name') : [];

if ($hasDb && $hasPortalColumns) {
    foreach ($clients as $client) {
        if (empty($client['support_slug']) || empty($client['support_token'])) {
            client_support_access($client);
        }
    }
    $clients = fetch_all('SELECT id, name, email, phone, city, sector, status, support_slug, support_token, support_enabled FROM clients ORDER BY name ASC');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
    $form = $_POST['form'] ?? '';

    if ($form === 'create') {
        if (!current_can('tickets.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/tickets.php'); }
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $equipmentId = (int) ($_POST['equipment_id'] ?? 0) ?: null;
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priority = trim((string) ($_POST['priority'] ?? 'Media'));
        $status = trim((string) ($_POST['status'] ?? 'Abierto'));
        $reportedBy = trim((string) ($_POST['reported_by'] ?? ''));
        $reportedEmail = trim((string) ($_POST['reported_email'] ?? ''));
        $reportedPhone = trim((string) ($_POST['reported_phone'] ?? ''));
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
        $dueAt = $_POST['due_at'] ?: null;

        if ($clientId <= 0 || $subject === '' || $description === '') {
            flash('warning', 'Cliente, asunto y descripción son obligatorios.');
        } else {
            if (column_exists('tickets', 'source')) {
                $stmt = db()->prepare('INSERT INTO tickets (client_id, equipment_id, subject, description, priority, status, source, reported_by, reported_email, reported_phone, assigned_to, due_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "interno", ?, ?, ?, ?, ?, NOW(), NOW())');
                $stmt->execute([$clientId, $equipmentId, $subject, $description, $priority, $status, $reportedBy, $reportedEmail, $reportedPhone, $assignedTo, $dueAt]);
            } else {
                $stmt = db()->prepare('INSERT INTO tickets (client_id, equipment_id, subject, description, priority, status, reported_by, reported_email, reported_phone, assigned_to, due_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $stmt->execute([$clientId, $equipmentId, $subject, $description, $priority, $status, $reportedBy, $reportedEmail, $reportedPhone, $assignedTo, $dueAt]);
            }
            $newId = (int) db()->lastInsertId();
            log_activity('ticket', $newId, 'ticket_creado', $subject);
            flash('success', 'Ticket creado.');
            redirect('crm/tickets.php?id=' . $newId);
        }
    }

    if ($form === 'update') {
        if (!current_can('tickets.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/tickets.php'); }
        $id = (int) ($_POST['id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'Abierto'));
        $priority = trim((string) ($_POST['priority'] ?? 'Media'));
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
        $dueAt = $_POST['due_at'] ?: null;
        $resolvedAt = in_array($status, ['Resuelto', 'Cerrado'], true) ? date('Y-m-d H:i:s') : null;
        $stmt = db()->prepare('UPDATE tickets SET status=?, priority=?, assigned_to=?, due_at=?, resolved_at=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$status, $priority, $assignedTo, $dueAt, $resolvedAt, $id]);

        // Automation: resolving a ticket linked to an asset stamps its service dates.
        if ($resolvedAt !== null) {
            $eqId = (int) (fetch_one('SELECT equipment_id FROM tickets WHERE id=?', [$id])['equipment_id'] ?? 0);
            if ($eqId > 0) {
                $interval = max(1, (int) setting_get('service_interval_days', '180'));
                if (column_exists('equipment', 'last_service_at')) {
                    db()->prepare('UPDATE equipment SET last_service_at=CURDATE(), next_service_at=DATE_ADD(CURDATE(), INTERVAL ? DAY), updated_at=NOW() WHERE id=?')->execute([$interval, $eqId]);
                } else {
                    db()->prepare('UPDATE equipment SET next_service_at=DATE_ADD(CURDATE(), INTERVAL ? DAY), updated_at=NOW() WHERE id=?')->execute([$interval, $eqId]);
                }
                log_activity('equipment', $eqId, 'service_done', 'Servicio registrado al resolver el ticket #' . $id);
            }
        }
        log_activity('ticket', $id, 'ticket_' . str_replace(' ', '_', strtolower($status)), null);
        flash('success', 'Ticket actualizado.');
        redirect('crm/tickets.php?id=' . $id);
    }

    if ($form === 'comment') {
        if (!current_can('tickets.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/tickets.php'); }
        $id = (int) ($_POST['ticket_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));
        $isInternal = isset($_POST['is_internal']) ? 1 : 0;
        if ($id > 0 && $body !== '') {
            $stmt = db()->prepare('INSERT INTO ticket_comments (ticket_id, user_id, author_name, body, is_internal, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$id, current_user()['id'] ?: null, current_user()['name'] ?? 'SCH', $body, $isInternal]);
            db()->prepare('UPDATE tickets SET updated_at=NOW() WHERE id=?')->execute([$id]);
            log_activity('ticket', $id, 'comentario', null);
            flash('success', 'Comentario agregado.');
            redirect('crm/tickets.php?id=' . $id);
        }
    }

    if ($form === 'portal') {
        if (!current_can('tickets.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/tickets.php'); }
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $action = trim((string) ($_POST['portal_action'] ?? 'enable'));
        $client = $clientId > 0 ? fetch_one('SELECT * FROM clients WHERE id=?', [$clientId]) : null;
        if ($client && $hasPortalColumns) {
            $access = client_support_access($client, false);
            $enabled = $action === 'disable' ? 0 : 1;
            // Rotate the token on regenerate AND on disable, so a leaked/shared
            // public link is permanently dead once the portal is paused.
            if ($action === 'regenerate' || $action === 'disable') {
                $access['token'] = bin2hex(random_bytes(16));
            }
            db()->prepare('UPDATE clients SET support_slug=?, support_token=?, support_enabled=?, updated_at=NOW() WHERE id=?')
                ->execute([$access['slug'], $access['token'], $enabled, $clientId]);
            flash('success', $action === 'regenerate' ? 'Link publico regenerado.' : 'Portal del cliente actualizado.');
        }
        redirect('crm/tickets.php?portal=1');
    }

    if ($form === 'delete') {
        if (!current_can('tickets.delete')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/tickets.php'); }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare('DELETE FROM tickets WHERE id=?')->execute([$id]);
            log_activity('ticket', $id, 'ticket_eliminado', null);
            flash('success', 'Ticket eliminado.');
        }
        redirect('crm/tickets.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para guardar tickets en MySQL.');
}

$selectedId = (int) ($_GET['id'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$priorityFilter = trim((string) ($_GET['priority'] ?? ''));
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$view = ($_GET['view'] ?? 'board') === 'list' ? 'list' : 'board';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$totalTickets = 0;
$totalPages = 1;

if ($hasDb) {
    $where = '1=1';
    $params = [];
    if ($statusFilter !== '') {
        $where .= ' AND tickets.status = ?';
        $params[] = $statusFilter;
    }
    if ($priorityFilter !== '') {
        $where .= ' AND tickets.priority = ?';
        $params[] = $priorityFilter;
    }
    if ($clientFilter > 0) {
        $where .= ' AND tickets.client_id = ?';
        $params[] = $clientFilter;
    }
    if ($q !== '') {
        $where .= ' AND (tickets.subject LIKE ? OR tickets.description LIKE ? OR clients.name LIKE ? OR equipment.name LIKE ? OR tickets.reported_by LIKE ?)';
        array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
    }

    $countRow = fetch_one("SELECT COUNT(*) AS total FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id LEFT JOIN equipment ON equipment.id = tickets.equipment_id WHERE {$where}", $params);
    $totalTickets = (int) ($countRow['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalTickets / $perPage));
    $sourceSelect = column_exists('tickets', 'source') ? 'tickets.source, tickets.public_reference,' : '"interno" AS source, NULL AS public_reference,';
    $tickets = fetch_all("SELECT tickets.*, {$sourceSelect} clients.name AS client_name, clients.email AS client_email, clients.phone AS client_phone, clients.support_slug, clients.support_token, equipment.name AS equipment_name, equipment.serial, equipment.area, users.name AS assigned_name FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id LEFT JOIN equipment ON equipment.id = tickets.equipment_id LEFT JOIN users ON users.id = tickets.assigned_to WHERE {$where} ORDER BY FIELD(tickets.status,'Abierto','En proceso','Cotizado','Resuelto','Cerrado'), FIELD(tickets.priority,'Critica','Alta','Media','Baja'), tickets.updated_at DESC LIMIT {$perPage} OFFSET {$offset}", $params);

    // Ensure the just-opened/selected ticket's modal always renders, even if it
    // falls outside the current page/filter (e.g. a ticket created as Resuelto).
    if ($selectedId > 0 && !array_filter($tickets, fn ($t) => (int) $t['id'] === $selectedId)) {
        $sel = fetch_one("SELECT tickets.*, {$sourceSelect} clients.name AS client_name, clients.email AS client_email, clients.phone AS client_phone, clients.support_slug, clients.support_token, equipment.name AS equipment_name, equipment.serial, equipment.area, users.name AS assigned_name FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id LEFT JOIN equipment ON equipment.id = tickets.equipment_id LEFT JOIN users ON users.id = tickets.assigned_to WHERE tickets.id = ?", [$selectedId]);
        if ($sel) { array_unshift($tickets, $sel); }
    }
    $stats = [
        'open' => db_count('tickets', "status IN ('Abierto','En proceso')"),
        'critical' => db_count('tickets', "priority IN ('Critica','Alta') AND status NOT IN ('Resuelto','Cerrado')"),
        'due' => db_count('tickets', "due_at IS NOT NULL AND due_at < CURDATE() AND status NOT IN ('Resuelto','Cerrado')"),
        'portal' => column_exists('tickets', 'source') ? db_count('tickets', "source='portal_cliente'") : 0,
    ];
} else {
    $tickets = [
        ['id' => 1001, 'client_name' => 'Hospital Metropolitano de Santiago', 'equipment_name' => 'Manifold Lifeline', 'subject' => 'Revision de presion en linea O2', 'description' => 'Datos demo. Ejecuta install.php para gestionar tickets reales.', 'priority' => 'Alta', 'status' => 'Abierto', 'reported_by' => 'Biomedica HMS', 'reported_email' => 'biomedica@hms.local', 'reported_phone' => '809-000-0000', 'assigned_name' => 'Tecnico SCH', 'assigned_to' => null, 'due_at' => date('Y-m-d', strtotime('+2 days')), 'source' => 'portal_cliente', 'public_reference' => 'WEB-DEMO-01', 'serial' => 'SCH-DEMO', 'area' => 'Emergencia', 'created_at' => date('Y-m-d'), 'updated_at' => date('Y-m-d')],
        ['id' => 1002, 'client_name' => 'Plaza de la Salud', 'equipment_name' => 'Lampara quirurgica', 'subject' => 'Mantenimiento preventivo vencido', 'description' => 'Programar visita de mantenimiento preventivo.', 'priority' => 'Media', 'status' => 'En proceso', 'reported_by' => 'Mantenimiento', 'reported_email' => 'biomedica@plaza.local', 'reported_phone' => '809-000-0001', 'assigned_name' => 'Soporte SCH', 'assigned_to' => null, 'due_at' => date('Y-m-d', strtotime('+5 days')), 'source' => 'interno', 'public_reference' => null, 'serial' => 'SCH-PDS-087', 'area' => 'Quirofano 2', 'created_at' => date('Y-m-d', strtotime('-1 day')), 'updated_at' => date('Y-m-d')],
    ];
    $commentsByTicket = [
        1001 => [['author_name' => 'Administrador SCH', 'body' => 'Ticket recibido y clasificado como alta prioridad.', 'is_internal' => 1, 'created_at' => date('Y-m-d H:i:s')]],
        1002 => [],
    ];
    $stats = ['open' => 2, 'critical' => 1, 'due' => 0, 'portal' => 1];
    $totalTickets = count($tickets);
    $totalPages = 1;
}

if (!isset($commentsByTicket)) {
    $commentsByTicket = [];
    foreach ($tickets as $ticket) {
        $commentsByTicket[(int) $ticket['id']] = fetch_all('SELECT * FROM ticket_comments WHERE ticket_id=? ORDER BY created_at ASC', [(int) $ticket['id']]);
    }
}

$boardStatuses = ['Abierto', 'En proceso', 'Cotizado', 'Resuelto', 'Cerrado'];
$ticketsByStatus = array_fill_keys($boardStatuses, []);
foreach ($tickets as $ticket) {
    $status = (string) ($ticket['status'] ?? 'Abierto');
    if (!isset($ticketsByStatus[$status])) {
        $ticketsByStatus[$status] = [];
    }
    $ticketsByStatus[$status][] = $ticket;
}

$absolute = function (string $relative): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $relative;
};

$queryForPage = function (int $nextPage) use ($statusFilter, $priorityFilter, $clientFilter, $q, $view): string {
    return http_build_query(array_filter([
        'view' => $view,
        'status' => $statusFilter,
        'priority' => $priorityFilter,
        'client_id' => $clientFilter ?: null,
        'q' => $q,
        'page' => $nextPage,
    ], fn ($value) => $value !== null && $value !== ''));
};

$viewUrl = function (string $nextView) use ($statusFilter, $priorityFilter, $clientFilter, $q): string {
    return url('crm/tickets.php?' . http_build_query(array_filter([
        'view' => $nextView,
        'status' => $statusFilter,
        'priority' => $priorityFilter,
        'client_id' => $clientFilter ?: null,
        'q' => $q,
    ], fn ($value) => $value !== null && $value !== '')));
};

$emptyTicket = ['id' => 0, 'client_id' => '', 'equipment_id' => '', 'subject' => '', 'priority' => 'Media', 'status' => 'Abierto', 'assigned_to' => '', 'due_at' => '', 'reported_by' => '', 'reported_email' => '', 'reported_phone' => '', 'description' => ''];
$crmTitle = 'Centro helpdesk';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar tickets.</div>
<?php endif; ?>

<div class="helpdesk-v2" x-data="crmFormModal(<?= e(json_encode($emptyTicket)) ?>, <?= isset($_GET['new']) ? '{}' : 'null' ?>)">
    <section class="helpdesk-v2__top">
        <div class="helpdesk-v2__hero">
            <span>Centro de soporte</span>
            <h2>Helpdesk por cliente, estado y prioridad</h2>
            <p>Tablero operativo para recibir tickets del portal publico, abrir casos internos, asignar responsables y cerrar seguimiento desde modales.</p>
            <div class="helpdesk-v2__actions">
                <button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus"></i>Nuevo ticket</button>
                <button type="button" class="crm-secondary-btn" onclick="document.getElementById('portal-links-modal').showModal()"><i data-lucide="link-2"></i>Portales por cliente</button>
            </div>
        </div>
        <div class="helpdesk-v2__metrics">
            <article><span>Abiertos</span><strong><?= e((string) $stats['open']) ?></strong></article>
            <article><span>Alta prioridad</span><strong><?= e((string) $stats['critical']) ?></strong></article>
            <article><span>Vencidos</span><strong><?= e((string) $stats['due']) ?></strong></article>
            <article><span>Portal</span><strong><?= e((string) $stats['portal']) ?></strong></article>
        </div>
    </section>

    <section class="helpdesk-v2__surface">
        <div class="helpdesk-viewbar">
            <div>
                <h3>Tickets</h3>
                <p>Alterna entre tablero visual y lista operativa.</p>
            </div>
            <nav aria-label="Cambiar vista de tickets">
                <a href="<?= e($viewUrl('board')) ?>" class="<?= $view === 'board' ? 'is-active' : '' ?>"><i data-lucide="layout-dashboard"></i>Tablero</a>
                <a href="<?= e($viewUrl('list')) ?>" class="<?= $view === 'list' ? 'is-active' : '' ?>"><i data-lucide="list"></i>Lista</a>
            </nav>
        </div>
        <form method="get" class="helpdesk-v2__filters">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <label>
                <span>Buscar</span>
                <input name="q" value="<?= e($q) ?>" class="crm-input" placeholder="Cliente, equipo, asunto o reportante">
            </label>
            <label>
                <span>Cliente</span>
                <select name="client_id" class="crm-select">
                    <option value="">Todos</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>" <?= $clientFilter === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Estado</span>
                <select name="status" class="crm-select">
                    <option value="">Todos</option>
                    <?php foreach ($boardStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Prioridad</span>
                <select name="priority" class="crm-select">
                    <option value="">Todas</option>
                    <?php foreach (['Critica','Alta','Media','Baja'] as $priority): ?>
                        <option value="<?= e($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= e($priority) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="crm-secondary-btn" type="submit"><i data-lucide="filter"></i>Aplicar</button>
        </form>

        <?php if ($view === 'board'): ?>
            <div class="helpdesk-board" aria-label="Tablero de tickets">
                <?php foreach ($boardStatuses as $status): ?>
                    <?php $laneTickets = $ticketsByStatus[$status] ?? []; ?>
                    <section class="helpdesk-lane">
                        <header>
                            <div>
                                <span class="helpdesk-lane__dot is-<?= e(strtolower(str_replace(' ', '-', $status))) ?>"></span>
                                <h3><?= e($status) ?></h3>
                            </div>
                            <b><?= e((string) count($laneTickets)) ?></b>
                        </header>
                        <div class="helpdesk-lane__grid">
                            <?php foreach ($laneTickets as $ticket): ?>
                                <?php $modalId = 'ticket-modal-' . (int) $ticket['id']; ?>
                                <?php $isOverdue = !empty($ticket['due_at']) && strtotime((string) $ticket['due_at']) < strtotime('today') && !in_array((string) $ticket['status'], ['Resuelto', 'Cerrado'], true); ?>
                                <button type="button" class="helpdesk-board-card is-<?= e(strtolower($ticket['priority'])) ?>" onclick="document.getElementById('<?= e($modalId) ?>').showModal()">
                                    <span class="helpdesk-board-card__meta">
                                        <strong>TK-<?= date('Y') ?>-<?= str_pad((string) $ticket['id'], 4, '0', STR_PAD_LEFT) ?></strong>
                                        <?php if (($ticket['source'] ?? '') === 'portal_cliente'): ?><em>Portal</em><?php endif; ?>
                                    </span>
                                    <span class="helpdesk-board-card__title"><?= e($ticket['subject']) ?></span>
                                    <span class="helpdesk-board-card__client"><?= e($ticket['client_name'] ?? 'Sin cliente') ?></span>
                                    <span class="helpdesk-board-card__foot">
                                        <small><?= e($ticket['priority']) ?></small>
                                        <?php if ($isOverdue): ?><small class="status-chip bg-red-50 text-red-700 ring-1 ring-red-200">Vencido</small><?php else: ?><small><?= e(date_es($ticket['due_at'] ?? null)) ?></small><?php endif; ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                            <?php if (!$laneTickets): ?>
                                <div class="helpdesk-lane__empty">Sin casos en este estado</div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="helpdesk-list-view" aria-label="Lista de tickets">
                <table class="helpdesk-ticket-table">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Cliente y equipo</th>
                            <th>Estado</th>
                            <th>Prioridad</th>
                            <th>Responsable</th>
                            <th>Vence</th>
                            <th>Origen</th>
                            <th class="text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <?php $modalId = 'ticket-modal-' . (int) $ticket['id']; ?>
                            <tr>
                                <td data-label="Ticket">
                                    <strong>TK-<?= date('Y') ?>-<?= str_pad((string) $ticket['id'], 4, '0', STR_PAD_LEFT) ?></strong>
                                    <p><?= e($ticket['subject']) ?></p>
                                </td>
                                <td data-label="Cliente">
                                    <b><?= e($ticket['client_name'] ?? 'Sin cliente') ?></b>
                                    <p><?= e($ticket['equipment_name'] ?? 'Sin equipo') ?></p>
                                </td>
                                <td data-label="Estado"><span class="status-chip <?= e(status_class($ticket['status'])) ?>"><?= e($ticket['status']) ?></span></td>
                                <td data-label="Prioridad"><span class="status-chip <?= e(priority_class($ticket['priority'])) ?>"><?= e($ticket['priority']) ?></span></td>
                                <td data-label="Responsable"><?= e($ticket['assigned_name'] ?? 'Sin asignar') ?></td>
                                <?php $isOverdue = !empty($ticket['due_at']) && strtotime((string) $ticket['due_at']) < strtotime('today') && !in_array((string) $ticket['status'], ['Resuelto', 'Cerrado'], true); ?>
                                <td data-label="Vence"><?php if ($isOverdue): ?><span class="status-chip bg-red-50 text-red-700 ring-1 ring-red-200">Vencido</span> <?php endif; ?><?= e(date_es($ticket['due_at'] ?? null)) ?></td>
                                <td data-label="Origen"><?= ($ticket['source'] ?? '') === 'portal_cliente' ? 'Portal' : 'Interno' ?></td>
                                <td data-label="Acción" class="text-right">
                                    <button type="button" class="crm-secondary-btn helpdesk-list-action" onclick="document.getElementById('<?= e($modalId) ?>').showModal()"><i data-lucide="panel-right-open"></i>Detalle</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$tickets): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="helpdesk-lane__empty">No hay tickets con estos filtros</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <footer class="helpdesk-v2__pager">
            <span><?= e((string) $totalTickets) ?> tickets visibles</span>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Paginación de tickets">
                    <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : url('crm/tickets.php?' . $queryForPage($page - 1)) ?>">Anterior</a>
                    <b><?= e((string) $page) ?> / <?= e((string) $totalPages) ?></b>
                    <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : url('crm/tickets.php?' . $queryForPage($page + 1)) ?>">Siguiente</a>
                </nav>
            <?php endif; ?>
        </footer>
    </section>

    <?php foreach ($tickets as $ticket): ?>
        <?php
            $ticketId = (int) $ticket['id'];
            $modalId = 'ticket-modal-' . $ticketId;
            $comments = $commentsByTicket[$ticketId] ?? [];
        ?>
        <dialog id="<?= e($modalId) ?>" class="helpdesk-ticket-modal" data-ticket-modal="<?= $ticketId ?>">
            <div class="helpdesk-ticket-modal__shell">
                <header class="helpdesk-ticket-modal__head">
                    <div>
                        <p>Ticket #<?= e((string) $ticketId) ?><?= !empty($ticket['public_reference']) ? ' &middot; ' . e($ticket['public_reference']) : '' ?></p>
                        <h2><?= e($ticket['subject']) ?></h2>
                        <span><?= e($ticket['client_name'] ?? 'Sin cliente') ?> &middot; <?= e($ticket['reported_by'] ?: 'Sin reportante') ?></span>
                    </div>
                    <button type="button" class="crm-modal__close" onclick="this.closest('dialog').close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
                </header>

                <div class="helpdesk-ticket-modal__body">
                    <section class="helpdesk-ticket-modal__main">
                        <div class="helpdesk-ticket-modal__summary">
                            <article><span>Estado</span><strong><?= e($ticket['status']) ?></strong></article>
                            <article><span>Prioridad</span><strong><?= e($ticket['priority']) ?></strong></article>
                            <article><span>Origen</span><strong><?= ($ticket['source'] ?? '') === 'portal_cliente' ? 'Portal cliente' : 'Interno' ?></strong></article>
                            <article><span>Vence</span><strong><?= e(date_es($ticket['due_at'] ?? null)) ?></strong></article>
                        </div>

                        <article class="helpdesk-ticket-modal__block">
                            <h3>Descripción técnica</h3>
                            <p><?= nl2br(e($ticket['description'] ?? 'Sin descripción')) ?></p>
                        </article>

                        <article class="helpdesk-ticket-modal__block">
                            <h3>Cliente y activo</h3>
                            <p><strong><?= e($ticket['client_name'] ?? 'Sin cliente') ?></strong><br><?= e($ticket['equipment_name'] ?? 'Sin equipo') ?> &middot; Serie <?= e($ticket['serial'] ?? 'Sin serie') ?><?= !empty($ticket['area']) ? ' &middot; ' . e($ticket['area']) : '' ?></p>
                            <p class="helpdesk-ticket-modal__contact"><?= e($ticket['reported_email'] ?: $ticket['client_email'] ?: 'Sin correo') ?> &middot; <?= e($ticket['reported_phone'] ?: $ticket['client_phone'] ?: 'Sin telefono') ?></p>
                        </article>

                        <article class="helpdesk-ticket-modal__block">
                            <div class="helpdesk-comments-head">
                                <h3>Timeline</h3>
                                <span><?= e((string) count($comments)) ?> notas</span>
                            </div>
                            <div class="helpdesk-timeline">
                                <?php if (!$comments): ?>
                                    <div class="helpdesk-lane__empty">Aun no hay notas para este ticket</div>
                                <?php endif; ?>
                                <?php foreach ($comments as $comment): ?>
                                    <article>
                                        <span class="helpdesk-timeline__dot"></span>
                                        <div>
                                            <header>
                                                <strong><?= e($comment['author_name']) ?></strong>
                                                <small><?= e(date_es($comment['created_at'])) ?><?= !empty($comment['is_internal']) ? ' &middot; Interno' : ' &middot; Cliente' ?></small>
                                            </header>
                                            <p><?= e($comment['body']) ?></p>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    </section>

                    <aside class="helpdesk-ticket-modal__side">
                        <form method="post" class="helpdesk-ticket-modal__form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="update">
                            <input type="hidden" name="id" value="<?= $ticketId ?>">
                            <label class="crm-field">
                                <span>Estado</span>
                                <select name="status" class="crm-select">
                                    <?php foreach ($boardStatuses as $status): ?>
                                        <option <?= ($ticket['status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="crm-field">
                                <span>Prioridad</span>
                                <select name="priority" class="crm-select">
                                    <?php foreach (['Baja','Media','Alta','Critica'] as $priority): ?>
                                        <option <?= ($ticket['priority'] ?? '') === $priority ? 'selected' : '' ?>><?= e($priority) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="crm-field">
                                <span>Asignado a</span>
                                <select name="assigned_to" class="crm-select">
                                    <option value="">Sin asignar</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= (int) $user['id'] ?>" <?= (int) ($ticket['assigned_to'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= e($user['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="crm-field">
                                <span>Vence</span>
                                <input type="date" name="due_at" value="<?= e($ticket['due_at'] ?? '') ?>" class="crm-input">
                            </label>
                            <button class="crm-primary-btn" type="submit"><i data-lucide="save"></i>Guardar cambios</button>
                        </form>

                        <form method="post" class="helpdesk-ticket-modal__form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="comment">
                            <input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
                            <label class="crm-field">
                                <span>Nueva nota</span>
                                <textarea name="body" rows="4" class="crm-textarea" placeholder="Comentario interno o seguimiento"></textarea>
                            </label>
                            <label class="helpdesk-note-check"><input type="checkbox" name="is_internal" checked> Nota interna</label>
                            <button class="crm-secondary-btn" type="submit"><i data-lucide="message-square-plus"></i>Agregar nota</button>
                        </form>

                        <form method="post" class="helpdesk-ticket-modal__form" onsubmit="return confirm('¿Eliminar este ticket? Esta acción no se puede deshacer.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete">
                            <input type="hidden" name="id" value="<?= $ticketId ?>">
                            <button class="crm-danger-btn" type="submit"><i data-lucide="trash-2"></i>Eliminar ticket</button>
                        </form>
                    </aside>
                </div>
            </div>
        </dialog>
    <?php endforeach; ?>

    <dialog id="portal-links-modal" class="helpdesk-portal-modal">
        <div class="helpdesk-portal-modal__shell">
            <header class="helpdesk-ticket-modal__head">
                <div>
                    <p>Portales publicos</p>
                    <h2>Links por cliente</h2>
                    <span>Cada empresa puede reportar tickets desde su propio formulario publico.</span>
                </div>
                <button type="button" class="crm-modal__close" onclick="this.closest('dialog').close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="helpdesk-portal-grid">
                <?php foreach ($clients as $client): ?>
                    <?php
                        $portalUrl = $hasPortalColumns ? $absolute(client_support_url($client)) : '#';
                        $enabled = (int) ($client['support_enabled'] ?? 1) === 1;
                    ?>
                    <article class="helpdesk-client-link <?= !$enabled ? 'is-paused' : '' ?>">
                        <div>
                            <strong><?= e($client['name']) ?></strong>
                            <span><?= e($client['city'] ?: $client['sector'] ?: 'Cliente SCH') ?></span>
                        </div>
                        <input readonly value="<?= e($portalUrl) ?>" aria-label="Link publico de <?= e($client['name']) ?>">
                        <div class="helpdesk-client-link__actions">
                            <button type="button" class="crm-secondary-btn" data-copy="<?= e($portalUrl) ?>"><i data-lucide="copy"></i>Copiar</button>
                            <a href="<?= e($portalUrl) ?>" target="_blank" rel="noopener" class="crm-secondary-btn"><i data-lucide="external-link"></i>Abrir</a>
                        </div>
                        <form method="post" class="helpdesk-client-link__forms">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="portal">
                            <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                            <button name="portal_action" value="<?= $enabled ? 'disable' : 'enable' ?>" class="crm-secondary-btn" type="submit"><?= $enabled ? 'Pausar' : 'Activar' ?></button>
                            <button name="portal_action" value="regenerate" class="crm-danger-btn" type="submit">Regenerar</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </dialog>

    <dialog x-ref="dlg" class="crm-modal crm-modal--wide" @click.self="close()" @cancel.prevent="close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="create">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="life-buoy"></i></span>
                <div class="crm-modal__titles">
                    <h2>Nuevo ticket interno</h2>
                    <p>Registra cliente, equipo, prioridad y descripción técnica.</p>
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
                    <label class="crm-field"><span>Equipo</span>
                        <select name="equipment_id" x-model="form.equipment_id" class="crm-select">
                            <option value="">Sin equipo especifico</option>
                            <?php foreach ($equipmentList as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e($item['client_name'] . ' - ' . $item['name'] . ' - ' . ($item['serial'] ?: 'sin serie')) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label class="crm-field"><span class="required">Asunto</span><input name="subject" required x-model="form.subject" class="crm-input"></label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Prioridad</span><select name="priority" x-model="form.priority" class="crm-select"><?php foreach (['Baja','Media','Alta','Critica'] as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>Estado</span><select name="status" x-model="form.status" class="crm-select"><?php foreach ($boardStatuses as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>Asignado a</span><select name="assigned_to" x-model="form.assigned_to" class="crm-select"><option value="">Sin asignar</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>Vence</span><input type="date" name="due_at" x-model="form.due_at" class="crm-input"></label>
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Reportado por</span><input name="reported_by" x-model="form.reported_by" class="crm-input"></label>
                    <label class="crm-field"><span>Correo reporte</span><input type="email" name="reported_email" x-model="form.reported_email" class="crm-input"></label>
                    <label class="crm-field"><span>Teléfono reporte</span><input name="reported_phone" x-model="form.reported_phone" class="crm-input"></label>
                </div>
                <label class="crm-field"><span class="required">Descripción</span><textarea name="description" required rows="5" x-model="form.description" class="crm-textarea"></textarea></label>
            </div>
            <footer class="crm-modal__foot">
                <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                <button type="submit" class="crm-primary-btn"><i data-lucide="check"></i>Crear ticket</button>
            </footer>
        </form>
    </dialog>
</div>

<?php if ($selectedId > 0): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var dialog = document.getElementById('ticket-modal-<?= (int) $selectedId ?>');
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
        });
    </script>
<?php endif; ?>
<?php if (isset($_GET['portal'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var dialog = document.getElementById('portal-links-modal');
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
        });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
