<?php
require_once __DIR__ . '/includes/bootstrap.php';
// The portal access token rides in the URL; never leak it via the Referer header.
if (!headers_sent()) {
    header('Referrer-Policy: no-referrer');
}
verify_csrf();

ensure_helpdesk_schema();

$pdo = db(false);
$slug = trim((string) ($_GET['cliente'] ?? ''));
$key = trim((string) ($_GET['key'] ?? ''));
$hasPortal = $pdo && table_exists('clients') && table_exists('tickets') && column_exists('clients', 'support_slug');
$client = null;
$equipmentList = [];

if ($hasPortal && $slug !== '' && $key !== '') {
    $client = fetch_one('SELECT * FROM clients WHERE support_slug=? AND support_token=? AND support_enabled=1 LIMIT 1', [$slug, $key]);
    if ($client) {
        $equipmentList = fetch_all('SELECT id, name, brand, model, serial, área, location FROM equipment WHERE client_id=? ORDER BY name ASC', [(int) $client['id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $client && $hasPortal) {
    $contact = trim((string) ($_POST['contact_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $equipmentId = (int) ($_POST['equipment_id'] ?? 0) ?: null;
    $equipmentName = trim((string) ($_POST['equipment_name'] ?? ''));
    $serial = trim((string) ($_POST['serial'] ?? ''));
    $área = trim((string) ($_POST['área'] ?? ''));
    $impact = trim((string) ($_POST['impact'] ?? 'Media'));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $availability = trim((string) ($_POST['availability'] ?? ''));

    if ($contact === '' || $email === '' || $subject === '' || $description === '') {
        flash('warning', 'Completa contacto, correo, asunto y descripción del caso.');
        redirect('helpdesk.php?cliente=' . rawurlencode($slug) . '&key=' . rawurlencode($key));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('warning', 'El correo del contacto no tiene un formato valido.');
        redirect('helpdesk.php?cliente=' . rawurlencode($slug) . '&key=' . rawurlencode($key));
    }

    if (!$equipmentId && ($equipmentName !== '' || $serial !== '' || $área !== '')) {
        $stmt = $pdo->prepare('INSERT INTO equipment (client_id, name, serial, área, location, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "requiere revisión", NOW(), NOW())');
        $stmt->execute([(int) $client['id'], $equipmentName ?: 'Equipo reportado por portal', $serial, $área, $área]);
        $equipmentId = (int) $pdo->lastInsertId();
    }

    $priority = in_array($impact, ['Baja', 'Media', 'Alta', 'Crítica'], true) ? $impact : 'Media';
    $reference = 'WEB-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    $detail = trim($description . "\n\nArea o departamento: " . ($department ?: 'No indicado') . "\nDisponibilidad: " . ($availability ?: 'No indicada'));

    $columns = 'client_id, equipment_id, subject, description, priority, status, reported_by, reported_email, reported_phone, created_at, updated_at';
    $placeholders = '?, ?, ?, ?, ?, "Abierto", ?, ?, ?, NOW(), NOW()';
    $params = [(int) $client['id'], $equipmentId, $subject, $detail, $priority, $contact, $email, $phone];

    if (column_exists('tickets', 'source') && column_exists('tickets', 'public_reference')) {
        $columns = 'client_id, equipment_id, subject, description, priority, status, source, public_reference, reported_by, reported_email, reported_phone, created_at, updated_at';
        $placeholders = '?, ?, ?, ?, ?, "Abierto", "portal_cliente", ?, ?, ?, ?, NOW(), NOW()';
        $params = [(int) $client['id'], $equipmentId, $subject, $detail, $priority, $reference, $contact, $email, $phone];
    }

    $stmt = $pdo->prepare("INSERT INTO tickets ({$columns}) VALUES ({$placeholders})");
    $stmt->execute($params);
    $ticketId = (int) $pdo->lastInsertId();

    $comment = 'Ticket recibido desde portal público de ' . ($client['name'] ?? 'cliente') . '. Referencia ' . $reference . '.';
    $stmt = $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, author_name, body, is_internal, created_at) VALUES (?, NULL, "Portal cliente", ?, 1, NOW())');
    $stmt->execute([$ticketId, $comment]);

    flash('success', 'Ticket ' . $reference . ' creado. SCH MEDICOS dara seguimiento al contacto indicado.');
    redirect('helpdesk.php?cliente=' . rawurlencode($slug) . '&key=' . rawurlencode($key) . '&ok=' . $ticketId);
}

$pageTitle = $client ? 'Centro de soporte ' . $client['name'] . ' | SCH MEDICOS' : 'Centro de soporte por cliente | SCH MEDICOS';
$pageDescription = 'Portal público de tickets para clientes institucionales SCH MEDICOS.';
$pageImage = asset('assets/media/Gases-2.png');
$canonical = current_url();
$bodyClass = 'helpdesk-public';
require_once __DIR__ . '/includes/public_header.php';
?>

<?php if (!$client): ?>
    <section class="helpdesk-portal helpdesk-portal--invalid">
        <div class="helpdesk-shell">
            <div class="helpdesk-invalid">
                <span class="helpdesk-invalid__icon"><i data-lucide="lock-keyhole"></i></span>
                <p>Portal de soporte</p>
                <h1>Este enlace no esta activo</h1>
                <span>Solicita a SCH MEDICOS un enlace vigente para tu empresa o reporta el caso por el formulario general.</span>
                <div class="helpdesk-invalid__actions">
                    <a href="<?= url('soporte.php') ?>" class="sch-btn-primary"><i data-lucide="life-buoy"></i>Formulario general</a>
                    <a href="https://wa.me/<?= APP_WHATSAPP ?>" class="sch-btn-outline-green"><i data-lucide="message-circle"></i>WhatsApp soporte</a>
                </div>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="helpdesk-portal" x-data="publicTicketWizard()" x-init="init()">
        <div class="helpdesk-shell">
            <aside class="helpdesk-rail" data-reveal="scale">
                <span class="helpdesk-rail__logo"><img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS" width="200" height="182"></span>
                <p class="helpdesk-kicker">Centro de helpdesk</p>
                <h1><?= e($client['name']) ?></h1>
                <span>Este canal crea tickets directos en la bandeja de soporte SCH MEDICOS con trazabilidad por empresa.</span>
                <div class="helpdesk-rail__meta">
                    <article><i data-lucide="building-2"></i><b><?= e($client['sector'] ?: 'Institucional') ?></b><small>Tipo de cliente</small></article>
                    <article><i data-lucide="map-pin"></i><b><?= e($client['city'] ?: 'República Dominicana') ?></b><small>Ubicación</small></article>
                    <article><i data-lucide="timer"></i><b>24/7</b><small>Entrada de reportes</small></article>
                </div>
                <div class="helpdesk-rail__contact">
                    <strong>Soporte inmediato</strong>
                    <a href="tel:+18095675559"><?= e(APP_PHONE) ?></a>
                    <a href="https://wa.me/<?= APP_WHATSAPP ?>">WhatsApp SCH</a>
                </div>
            </aside>

            <form method="post" class="helpdesk-wizard" data-reveal="scale" novalidate>
                <?= csrf_field() ?>
                <div class="helpdesk-wizard__top">
                    <div>
                        <p>Nuevo ticket</p>
                        <h2 x-text="titles[step - 1]">Contacto del reporte</h2>
                    </div>
                    <span class="helpdesk-step-count">Paso <b x-text="step">1</b> de 3</span>
                </div>

                <div class="helpdesk-progress" aria-hidden="true">
                    <span :class="step >= 1 ? 'is-active' : ''"></span>
                    <span :class="step >= 2 ? 'is-active' : ''"></span>
                    <span :class="step >= 3 ? 'is-active' : ''"></span>
                </div>

                <div class="helpdesk-step" x-show="step === 1" x-transition.opacity>
                    <div class="helpdesk-form-grid">
                        <label class="sch-field">
                            <span>Nombre del contacto *</span>
                            <input name="contact_name" x-model="fields.contact_name" autocomplete="name" placeholder="Nombre y apellido">
                        </label>
                        <label class="sch-field">
                            <span>Correo institucional *</span>
                            <input type="email" name="email" x-model="fields.email" autocomplete="email" placeholder="correo@empresa.com">
                        </label>
                        <label class="sch-field">
                            <span>Teléfono directo</span>
                            <input name="phone" x-model="fields.phone" autocomplete="tel" placeholder="Extension, movil o WhatsApp">
                        </label>
                        <label class="sch-field">
                            <span>Área o departamento</span>
                            <input name="department" x-model="fields.department" placeholder="Emergencia, quirófano, biomédica">
                        </label>
                    </div>
                </div>

                <div class="helpdesk-step" x-show="step === 2" x-transition.opacity>
                    <div class="helpdesk-form-grid">
                        <label class="sch-field sch-field--full">
                            <span>Equipo registrado</span>
                            <select name="equipment_id" x-model="fields.equipment_id">
                                <option value="">No estoy seguro o no aparece</option>
                                <?php foreach ($equipmentList as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>"><?= e($item['name'] . ' - ' . ($item['serial'] ?: $item['área'] ?: 'sin serie')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="sch-field">
                            <span>Equipo o sistema</span>
                            <input name="equipment_name" x-model="fields.equipment_name" placeholder="Manifold, lampara, red de gases">
                        </label>
                        <label class="sch-field">
                            <span>Serie, sala o ubicación</span>
                            <input name="serial" x-model="fields.serial" placeholder="Serie, piso, sala o código interno">
                        </label>
                        <label class="sch-field sch-field--full">
                            <span>Área afectada</span>
                            <input name="área" x-model="fields.área" placeholder="Ej. Emergencia, quirófano 2, central de gases">
                        </label>
                    </div>
                </div>

                <div class="helpdesk-step" x-show="step === 3" x-transition.opacity>
                    <div class="helpdesk-impact">
                        <?php foreach (['Baja', 'Media', 'Alta', 'Crítica'] as $impact): ?>
                            <label :class="fields.impact === '<?= e($impact) ?>' ? 'is-selected' : ''">
                                <input type="radio" name="impact" value="<?= e($impact) ?>" x-model="fields.impact">
                                <span><?= e($impact) ?></span>
                                <small><?= e(match ($impact) {
                                    'Baja' => 'Consulta o ajuste menor',
                                    'Media' => 'Afecta el flujo normal',
                                    'Alta' => 'Área clínica limitada',
                                    default => 'Servicio detenido o riesgo',
                                }) ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="sch-field">
                        <span>Asunto del ticket *</span>
                        <input name="subject" x-model="fields.subject" placeholder="Ej. Presión irregular en linea O2">
                    </label>
                    <label class="sch-field">
                        <span>Descripción técnica *</span>
                        <textarea name="description" rows="6" x-model="fields.description" placeholder="Describe síntomas, alarmas, hora aproximada, área afectada y acciones realizadas."></textarea>
                    </label>
                    <label class="sch-field">
                        <span>Disponibilidad para visita</span>
                        <input name="availability" x-model="fields.availability" placeholder="Hoy después de las 2:00 p.m., mañana en la mañana, etc.">
                    </label>
                </div>

                <p class="helpdesk-error" x-show="error" x-text="error" x-cloak></p>

                <div class="helpdesk-wizard__foot">
                    <button type="button" class="crm-secondary-btn" @click="back()" x-show="step > 1" x-cloak><i data-lucide="arrow-left"></i>Anterior</button>
                    <span></span>
                    <button type="button" class="crm-primary-btn" @click="next()" x-show="step < 3"><i data-lucide="arrow-right"></i>Continuar</button>
                    <button type="submit" class="crm-primary-btn" x-show="step === 3" @click="validateFinal($event)" x-cloak><i data-lucide="send"></i>Enviar ticket</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
