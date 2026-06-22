<?php
require_once __DIR__ . '/includes/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db(false);
    $company = trim((string) ($_POST['company'] ?? ''));
    $contact = trim((string) ($_POST['contact_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $equipment = trim((string) ($_POST['equipment'] ?? ''));
    $serial = trim((string) ($_POST['serial'] ?? ''));
    $priority = trim((string) ($_POST['priority'] ?? 'Media'));
    if (!in_array($priority, ['Baja', 'Media', 'Alta', 'Crítica'], true)) { $priority = 'Media'; }
    $description = trim((string) ($_POST['description'] ?? ''));

    // Silently drop obvious bot spam: honeypot filled, submitted too fast, or
    // content no real report would contain.
    if (trim((string) ($_POST['website'] ?? '')) !== ''
        || !form_time_ok()
        || looks_like_spam([$company, $contact, $phone, $equipment, $serial], $description)) {
        redirect('soporte.php');
    }

    if (!form_throttle_ok('soporte')) {
        flash('warning', 'Recibimos varios reportes desde tu conexión. Intenta de nuevo en unos minutos.');
        redirect('soporte.php');
    } elseif (!turnstile_verify()) {
        flash('warning', 'No pudimos verificar que no eres un robot. Intenta de nuevo.');
    } elseif ($company === '' || $contact === '' || $email === '' || $description === '') {
        flash('warning', 'Completa empresa, contacto, correo y descripción del problema.');
    } elseif ($pdo && table_exists('tickets')) {
        $pdo->beginTransaction();
        $client = fetch_one('SELECT * FROM clients WHERE name = ? OR email = ? LIMIT 1', [$company, $email]);
        if (!$client) {
            $stmt = $pdo->prepare('INSERT INTO clients (name, email, phone, status, created_at) VALUES (?, ?, ?, "activo", NOW())');
            $stmt->execute([$company, $email, $phone]);
            $clientId = (int) $pdo->lastInsertId();
        } else {
            $clientId = (int) $client['id'];
        }

        $equipmentId = null;
        if ($equipment !== '' || $serial !== '') {
            $found = $serial !== '' ? fetch_one('SELECT * FROM equipment WHERE serial = ? LIMIT 1', [$serial]) : null;
            if ($found) {
                $equipmentId = (int) $found['id'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO equipment (client_id, name, serial, status, created_at) VALUES (?, ?, ?, "requiere revisión", NOW())');
                $stmt->execute([$clientId, $equipment ?: 'Equipo reportado', $serial]);
                $equipmentId = (int) $pdo->lastInsertId();
            }
        }

        $stmt = $pdo->prepare('INSERT INTO tickets (client_id, equipment_id, subject, description, priority, status, reported_by, reported_email, reported_phone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "Abierto", ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$clientId, $equipmentId, 'Reporte externo: ' . $company, $description, $priority, $contact, $email, $phone]);
        $ticketId = (int) $pdo->lastInsertId();
        $pdo->commit();
        flash('success', 'Ticket #' . $ticketId . ' registrado. El equipo de soporte puede verlo en el CRM.');
        redirect('soporte.php');
    } else {
        flash('warning', 'Solicitud validada en modo local. Ejecuta install.php para guardar tickets en MySQL.');
    }
}

$pageTitle = 'Soporte técnico SCH MEDICOS | Reportar fallas en equipos médicos';
$pageDescription = 'Formulario de soporte para reportar problemas en equipos médicos, sistemas de gases medicinales o instalaciones hospitalarias atendidas por SCH MEDICOS.';
$pageImage = asset('assets/media/og-cover.png');
$bodyClass = 'sx';
$pageStyles = ['assets/css/site-v2.css'];
$pageFontsGeist = true;

$steps = [
    ['Paso 01', 'Empresa y contacto', 'Ubica responsable, teléfono y correo para dar seguimiento.'],
    ['Paso 02', 'Serie o ubicación', 'Relaciona historial, garantía y mantenimientos previos.'],
    ['Paso 03', 'Prioridad', 'El caso entra al panel con estado, técnico y vencimiento.'],
    ['Paso 04', 'Seguimiento', 'Ventas, soporte e ingeniería comparten el mismo registro.'],
];

require_once __DIR__ . '/includes/public_header.php';
?>

<!-- COVER (graphite sign-off band) -->
<section class="sx-cover sx-cover--graphite sx-sec sx-sec--tight" aria-label="Soporte técnico">
    <div class="sx-container">
        <span class="sx-kicker sx-kicker--light">Soporte técnico 24/7</span>
        <h1 class="sx-cover__title">Reporta fallas en equipos, gases o infraestructura médica.</h1>
        <p class="sx-cover__lead">El formulario crea un ticket, relaciona la empresa, registra el equipo y deja el caso listo para seguimiento técnico dentro del CRM.</p>
        <div class="sx-cover__actions">
            <a href="#reporte" class="sx-btn sx-btn--ondark"><i data-lucide="ticket-plus"></i>Crear ticket</a>
            <a href="https://wa.me/<?= APP_WHATSAPP ?>" class="sx-link sx-link--light">WhatsApp soporte<i data-lucide="message-circle"></i></a>
        </div>
    </div>
</section>

<!-- FLOW LEDGER -->
<section class="sx-sec sx-sec--tight sx-sec--paper" aria-label="Flujo de soporte">
    <div class="sx-container">
        <div class="sx-steps" data-reveal>
            <?php foreach ($steps as $s): ?>
                <div class="sx-step">
                    <div class="sx-step__code"><?= e($s[0]) ?></div>
                    <div class="sx-step__title"><?= e($s[1]) ?></div>
                    <p class="sx-step__desc"><?= e($s[2]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- FORM -->
<section id="reporte" class="sx-sec" aria-label="Reporte de soporte">
    <div class="sx-container sx-work">
        <div data-reveal="left">
            <h2 class="sx-h2" style="font-size:var(--t-h3)">Como preparar un buen reporte</h2>
            <div class="sx-channels" style="margin-top:1.4rem">
                <div class="sx-channel">
                    <i data-lucide="timer"></i>
                    <div><h3>Clasificacion rápida</h3><p>Selecciona prioridad según impacto operativo: baja, media, alta o crítica.</p></div>
                </div>
                <div class="sx-channel">
                    <i data-lucide="scan-line"></i>
                    <div><h3>Identificacion del activo</h3><p>Incluye equipo, sistema, serie, área clínica o ubicación exacta.</p></div>
                </div>
                <div class="sx-channel">
                    <i data-lucide="activity"></i>
                    <div><h3>Descripción util</h3><p>Describe síntomas, alarmas, códigos de error y si la falla es intermitente.</p></div>
                </div>
                <div class="sx-channel">
                    <i data-lucide="headset"></i>
                    <div>
                        <h3>Emergencia crítica 24/7</h3>
                        <p>Si una falla detiene un área clínica, llama directo.</p>
                        <a href="tel:+18095675559"><?= e(APP_PHONE) ?></a>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" class="sch-public-form" data-reveal="right" data-reveal-delay="100">
            <?= csrf_field() ?>
            <?= form_time_field() ?>
            <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
            <h2 class="sx-h2" style="font-size:1.4rem;margin-bottom:1.2rem">Reporte de soporte</h2>
            <div class="sch-form-grid">
                <label class="sch-field">
                    <span class="required">Empresa</span>
                    <input name="company" required autocomplete="organization">
                </label>
                <label class="sch-field">
                    <span class="required">Contacto</span>
                    <input name="contact_name" required autocomplete="name">
                </label>
                <label class="sch-field">
                    <span class="required">Correo</span>
                    <input type="email" name="email" required autocomplete="email">
                </label>
                <label class="sch-field">
                    <span>Teléfono</span>
                    <input name="phone" autocomplete="tel">
                </label>
                <label class="sch-field">
                    <span>Equipo o sistema</span>
                    <input name="equipment" placeholder="Ej. manifold, lampara, red de gases">
                </label>
                <label class="sch-field">
                    <span>Serie o ubicación</span>
                    <input name="serial" placeholder="Serie, sala, piso o área">
                </label>
                <label class="sch-field sch-field--full">
                    <span>Prioridad</span>
                    <select name="priority">
                        <option>Baja</option>
                        <option selected>Media</option>
                        <option>Alta</option>
                        <option>Crítica</option>
                    </select>
                </label>
                <label class="sch-field sch-field--full">
                    <span class="required">Descripción del problema</span>
                    <textarea name="description" required rows="6" placeholder="Describe síntomas, hora aproximada, alarmas, códigos y acciones realizadas."></textarea>
                </label>
            </div>
            <?php if (turnstile_enabled()): ?><div style="margin-top:1rem"><?= turnstile_widget() ?></div><?php endif; ?>
            <div class="sch-form-actions">
                <p>Los campos marcados con * son obligatorios para abrir el ticket.</p>
                <button class="sch-btn-primary" type="submit"><i data-lucide="send" class="h-4 w-4"></i>Registrar ticket</button>
            </div>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
