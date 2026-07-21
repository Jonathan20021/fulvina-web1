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
        $equipmentList = fetch_all('SELECT id, name, brand, model, serial, area, location FROM equipment WHERE client_id=? ORDER BY name ASC', [(int) $client['id']]);
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
    $area = trim((string) ($_POST['area'] ?? ''));
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

    if (!$equipmentId && ($equipmentName !== '' || $serial !== '' || $area !== '')) {
        $stmt = $pdo->prepare('INSERT INTO equipment (client_id, name, serial, area, location, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "requiere revisión", NOW(), NOW())');
        $stmt->execute([(int) $client['id'], $equipmentName ?: 'Equipo reportado por portal', $serial, $area, $area]);
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

    // Sin flash: la confirmación completa (referencia + próximos pasos) se
    // muestra en la pantalla de éxito del portal.
    redirect('helpdesk.php?cliente=' . rawurlencode($slug) . '&key=' . rawurlencode($key) . '&ok=' . $ticketId);
}

/* Ticket recién creado -> pantalla de confirmación en lugar del formulario. */
$createdTicket = null;
if ($client && isset($_GET['ok'])) {
    $createdTicket = fetch_one(
        'SELECT * FROM tickets WHERE id=? AND client_id=? LIMIT 1',
        [(int) $_GET['ok'], (int) $client['id']]
    );
}

$portalLink = 'helpdesk.php?cliente=' . rawurlencode($slug) . '&key=' . rawurlencode($key);

/* Catálogo de equipos para el paso de revisión del asistente. */
$equipmentOptions = [];
foreach ($equipmentList as $item) {
    $equipmentOptions[] = [
        'id' => (string) (int) $item['id'],
        'label' => $item['name'] . ' - ' . ($item['serial'] ?: $item['area'] ?: 'sin serie'),
    ];
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
    <section class="helpdesk-portal" x-data="publicTicketWizard(<?= e(json_encode(['storageKey' => 'sch-helpdesk-' . $slug, 'equipment' => $equipmentOptions], JSON_UNESCAPED_UNICODE)) ?>)" x-init="init()">
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
                <ul class="helpdesk-rail__checklist">
                    <li><i data-lucide="check"></i>Nombre y correo de quien reporta</li>
                    <li><i data-lucide="check"></i>Equipo, serie y área afectada</li>
                    <li><i data-lucide="check"></i>Qué falla y desde cuándo</li>
                </ul>
                <div class="helpdesk-rail__contact">
                    <strong>Soporte inmediato</strong>
                    <p>Si el equipo está detenido o hay riesgo clínico, llama antes de abrir el ticket.</p>
                    <a href="<?= tel_href(APP_PHONE) ?>">
                        <i data-lucide="phone"></i>
                        <span><?= e(APP_PHONE) ?></span>
                        <small>Central</small>
                    </a>
                    <a href="<?= tel_href(APP_PHONE_SUPPORT) ?>">
                        <i data-lucide="headset"></i>
                        <span><?= e(APP_PHONE_SUPPORT) ?></span>
                        <small>Soporte técnico</small>
                    </a>
                    <a href="https://wa.me/<?= APP_WHATSAPP ?>" target="_blank" rel="noopener">
                        <i data-lucide="message-circle"></i>
                        <span>WhatsApp SCH</span>
                        <small>Respuesta en horario laboral</small>
                    </a>
                </div>
            </aside>

<?php if ($createdTicket): ?>
                <article class="helpdesk-done" data-reveal="scale">
                    <span class="helpdesk-done__icon"><i data-lucide="check"></i></span>
                    <p class="helpdesk-kicker">Ticket recibido</p>
                    <h2>Ya está en la bandeja de soporte</h2>
                    <?php if (!empty($createdTicket['public_reference'])): ?>
                        <div class="helpdesk-done__ref">
                            <small>Número de referencia</small>
                            <b><?= e($createdTicket['public_reference']) ?></b>
                            <button type="button" class="crm-secondary-btn" data-copy="<?= e($createdTicket['public_reference']) ?>"><i data-lucide="copy"></i>Copiar</button>
                        </div>
                    <?php endif; ?>
                    <dl class="helpdesk-done__summary">
                        <div><dt>Asunto</dt><dd><?= e($createdTicket['subject']) ?></dd></div>
                        <div><dt>Prioridad</dt><dd><?= e($createdTicket['priority']) ?></dd></div>
                        <div><dt>Confirmación a</dt><dd><?= e($createdTicket['reported_email'] ?: 'correo indicado') ?></dd></div>
                    </dl>
                    <ol class="helpdesk-done__next">
                        <li><b>1. Clasificación</b><span>Soporte revisa el caso y asigna al técnico según la prioridad reportada.</span></li>
                        <li><b>2. Contacto</b><span>Te escribimos o llamamos al contacto indicado para coordinar el acceso.</span></li>
                        <li><b>3. Intervención</b><span>Visita o asistencia remota, con cierre documentado en tu historial.</span></li>
                    </ol>
                    <div class="helpdesk-done__actions">
                        <a href="<?= url($portalLink) ?>" class="crm-primary-btn"><i data-lucide="plus"></i>Reportar otro caso</a>
                        <a href="<?= tel_href(APP_PHONE_SUPPORT) ?>" class="crm-secondary-btn"><i data-lucide="phone"></i>Es urgente, llamar</a>
                    </div>
                </article>
            <?php else: ?>
            <form method="post" class="helpdesk-wizard" data-reveal="scale" novalidate @keydown.enter="onEnter($event)" @submit="onSubmit($event)">
                <?= csrf_field() ?>
                <div class="helpdesk-wizard__top">
                    <div>
                        <p>Nuevo ticket</p>
                        <h2 x-text="titles[step - 1]">Contacto del reporte</h2>
                        <small x-text="hints[step - 1]">Necesitamos saber a quién buscar cuando el técnico tome el caso.</small>
                    </div>
                    <span class="helpdesk-step-count">Paso <b x-text="step">1</b> de 4</span>
                </div>

                <ol class="helpdesk-progress">
                    <template x-for="(title, i) in titles" :key="title">
                        <li>
                            <button type="button"
                                    :class="{ 'is-active': step === i + 1, 'is-done': step > i + 1 }"
                                    :aria-current="step === i + 1 ? 'step' : false"
                                    @click="goTo(i + 1)">
                                <b x-text="i + 1"></b><span x-text="title"></span>
                            </button>
                        </li>
                    </template>
                </ol>

                <div class="helpdesk-step" x-show="step === 1" x-transition.opacity>
                    <div class="helpdesk-form-grid">
                        <label class="sch-field" :class="errors.contact_name && 'has-error'">
                            <span>Nombre del contacto *</span>
                            <input name="contact_name" x-model="fields.contact_name" @input="clear('contact_name')" autocomplete="name" placeholder="Nombre y apellido" :aria-invalid="!!errors.contact_name">
                            <small class="sch-field__error" x-show="errors.contact_name" x-text="errors.contact_name" x-cloak></small>
                        </label>
                        <label class="sch-field" :class="errors.email && 'has-error'">
                            <span>Correo institucional *</span>
                            <input type="email" name="email" x-model="fields.email" @input="clear('email')" autocomplete="email" inputmode="email" placeholder="correo@empresa.com" :aria-invalid="!!errors.email">
                            <small class="sch-field__error" x-show="errors.email" x-text="errors.email" x-cloak></small>
                            <small class="sch-field__hint" x-show="!errors.email">Ahí llega la confirmación y el seguimiento del ticket.</small>
                        </label>
                        <label class="sch-field">
                            <span>Teléfono directo</span>
                            <input name="phone" x-model="fields.phone" autocomplete="tel" inputmode="tel" placeholder="Extensión, móvil o WhatsApp">
                        </label>
                        <label class="sch-field">
                            <span>Área o departamento</span>
                            <input name="department" x-model="fields.department" placeholder="Emergencia, quirófano, biomédica">
                        </label>
                    </div>
                    <p class="helpdesk-note" x-show="remembered" x-cloak><i data-lucide="user-check"></i><span>Reusamos tus datos del último reporte. <button type="button" @click="forget()">No soy yo</button></span></p>
                </div>

                <div class="helpdesk-step" x-show="step === 2" x-transition.opacity x-cloak>
                    <div class="helpdesk-form-grid">
                        <label class="sch-field sch-field--full">
                            <span>Equipo registrado</span>
                            <select name="equipment_id" x-model="fields.equipment_id">
                                <option value="">No estoy seguro o no aparece</option>
                                <?php foreach ($equipmentList as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>"><?= e($item['name'] . ' - ' . ($item['serial'] ?: $item['area'] ?: 'sin serie')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="sch-field__hint"><?= $equipmentList ? 'Elígelo de tu inventario para que el técnico llegue con el repuesto correcto.' : 'Aún no tienes equipos registrados: descríbelo abajo y lo damos de alta.' ?></small>
                        </label>
                    </div>
                    <div class="helpdesk-manual" :class="fields.equipment_id && 'is-muted'">
                        <p x-show="fields.equipment_id" x-cloak><i data-lucide="info"></i>Ya seleccionaste un equipo del inventario. Completa lo de abajo solo si el reporte es de otro activo.</p>
                        <div class="helpdesk-form-grid">
                            <label class="sch-field">
                                <span>Equipo o sistema</span>
                                <input name="equipment_name" x-model="fields.equipment_name" placeholder="Manifold, lámpara, red de gases">
                            </label>
                            <label class="sch-field">
                                <span>Serie, sala o ubicación</span>
                                <input name="serial" x-model="fields.serial" placeholder="Serie, piso, sala o código interno">
                            </label>
                            <label class="sch-field sch-field--full">
                                <span>Área afectada</span>
                                <input name="area" x-model="fields.area" placeholder="Ej. Emergencia, quirófano 2, central de gases">
                            </label>
                        </div>
                    </div>
                </div>

                <div class="helpdesk-step" x-show="step === 3" x-transition.opacity x-cloak>
                    <fieldset class="helpdesk-impact-set">
                        <legend>Impacto en la operación</legend>
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
                    </fieldset>
                    <label class="sch-field" :class="errors.subject && 'has-error'">
                        <span>Asunto del ticket *</span>
                        <input name="subject" x-model="fields.subject" @input="clear('subject')" placeholder="Ej. Presión irregular en línea de O2" :aria-invalid="!!errors.subject">
                        <small class="sch-field__error" x-show="errors.subject" x-text="errors.subject" x-cloak></small>
                    </label>
                    <label class="sch-field" :class="errors.description && 'has-error'">
                        <span>Descripción técnica *</span>
                        <textarea name="description" rows="6" x-model="fields.description" @input="clear('description')" placeholder="Describe síntomas, alarmas, hora aproximada, área afectada y acciones realizadas." :aria-invalid="!!errors.description"></textarea>
                        <small class="sch-field__error" x-show="errors.description" x-text="errors.description" x-cloak></small>
                        <small class="sch-field__hint" x-show="!errors.description">Mientras más detalle, menos visitas de diagnóstico. <b x-text="fields.description.length"></b> caracteres.</small>
                    </label>
                    <label class="sch-field">
                        <span>Disponibilidad para visita</span>
                        <input name="availability" x-model="fields.availability" placeholder="Hoy después de las 2:00 p.m., mañana en la mañana, etc.">
                    </label>
                </div>

                <div class="helpdesk-step" x-show="step === 4" x-transition.opacity x-cloak>
                    <dl class="helpdesk-review">
                        <template x-for="row in review" :key="row.label">
                            <div :class="row.empty && 'is-empty'">
                                <dt x-text="row.label"></dt>
                                <dd><span x-text="row.value"></span><button type="button" @click="goTo(row.step)">Editar</button></dd>
                            </div>
                        </template>
                    </dl>
                    <p class="helpdesk-note"><i data-lucide="shield-check"></i><span>Al enviar, el caso entra con trazabilidad a nombre de <?= e($client['name']) ?> y recibes un número de referencia.</span></p>
                </div>

                <p class="helpdesk-error" x-show="error" x-text="error" role="alert" aria-live="polite" x-cloak></p>

                <div class="helpdesk-wizard__foot">
                    <button type="button" class="crm-secondary-btn" @click="back()" x-show="step > 1" x-cloak><i data-lucide="arrow-left"></i>Anterior</button>
                    <span></span>
                    <button type="button" class="crm-primary-btn" @click="next()" x-show="step < 4"><i data-lucide="arrow-right"></i>Continuar</button>
                    <button type="submit" class="crm-primary-btn" x-show="step === 4" x-cloak :disabled="sending"><i data-lucide="send"></i><span x-text="sending ? 'Enviando…' : 'Enviar ticket'">Enviar ticket</span></button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
