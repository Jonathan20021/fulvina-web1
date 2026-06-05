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
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($company === '' || $contact === '' || $email === '' || $description === '') {
        flash('warning', 'Completa empresa, contacto, correo y descripcion del problema.');
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
                $stmt = $pdo->prepare('INSERT INTO equipment (client_id, name, serial, status, created_at) VALUES (?, ?, ?, "requiere revision", NOW())');
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

$pageTitle = 'Soporte tecnico SCH MEDICOS | Reportar fallas en equipos medicos';
$pageDescription = 'Formulario de soporte para reportar problemas en equipos medicos, sistemas de gases medicinales o instalaciones hospitalarias atendidas por SCH MEDICOS.';
$pageImage = asset('assets/media/Gases-2.png');
require_once __DIR__ . '/includes/public_header.php';
?>

<section class="sch-page-hero">
    <div class="container-sch sch-page-hero__inner">
        <div data-reveal="left">
            <span class="sch-eyebrow">Soporte tecnico 24/7</span>
            <h1>Reporta fallas en equipos, gases o infraestructura medica</h1>
            <p>El formulario crea un ticket, relaciona la empresa, registra el equipo y deja el caso listo para seguimiento tecnico dentro del CRM.</p>
            <div class="sch-page-hero__actions">
                <a href="#reporte" class="sch-btn-primary"><i data-lucide="ticket-plus" class="h-5 w-5"></i>Crear ticket</a>
                <a href="https://wa.me/<?= APP_WHATSAPP ?>" class="sch-btn-outline-green"><i data-lucide="message-circle" class="h-5 w-5"></i>WhatsApp soporte</a>
            </div>
        </div>
        <div class="sch-page-hero__media" data-reveal="right" data-reveal-delay="120">
            <img src="<?= asset('assets/media/Gases-2.png') ?>" alt="Sistema de gases medicinales atendido por SCH MEDICOS">
        </div>
    </div>
</section>

<section class="sch-section sch-section--white" style="padding-top:0">
    <div class="container-sch" style="margin-top:-2.2rem;position:relative;z-index:5">
        <div class="sch-proof-band" aria-label="Flujo de soporte" data-reveal>
            <article>
                <span>Paso 1</span>
                <strong>Empresa y contacto</strong>
                <p>Ubica responsable, telefono y correo para dar seguimiento.</p>
            </article>
            <article>
                <span>Paso 2</span>
                <strong>Serie o ubicacion</strong>
                <p>Relaciona historial, garantia y mantenimientos previos.</p>
            </article>
            <article>
                <span>Paso 3</span>
                <strong>Prioridad</strong>
                <p>El caso entra al panel con estado, tecnico y vencimiento.</p>
            </article>
            <article>
                <span>Paso 4</span>
                <strong>Seguimiento CRM</strong>
                <p>Ventas, soporte e ingenieria comparten el mismo registro.</p>
            </article>
        </div>
    </div>
</section>

<section id="reporte" class="sch-section sch-section--white" style="padding-top:1rem">
    <div class="container-sch sch-contact-layout">
        <div class="sch-contact-stack" data-reveal="left">
            <div class="sch-contact-card">
                <i data-lucide="timer" class="h-6 w-6"></i>
                <div>
                    <h2>Clasificacion rapida</h2>
                    <p>Selecciona prioridad segun impacto operativo: baja, media, alta o critica.</p>
                </div>
            </div>
            <div class="sch-contact-card">
                <i data-lucide="scan-line" class="h-6 w-6"></i>
                <div>
                    <h2>Identificacion del activo</h2>
                    <p>Incluye equipo, sistema, serie, area clinica o ubicacion exacta.</p>
                </div>
            </div>
            <div class="sch-contact-card">
                <i data-lucide="activity" class="h-6 w-6"></i>
                <div>
                    <h2>Descripcion util</h2>
                    <p>Describe sintomas, alarmas, codigos de error y si la falla es intermitente.</p>
                </div>
            </div>
            <div class="sch-support-cta">
                <span class="sch-support-cta__icon"><i data-lucide="headset" class="h-6 w-6"></i></span>
                <span class="sch-eyebrow sch-eyebrow--light">Soporte inmediato 24/7</span>
                <h3>Falla critica que detiene un area clinica?</h3>
                <p>No esperes. Nuestro equipo tecnico atiende emergencias hospitalarias de inmediato.</p>
                <div class="sch-support-cta__actions">
                    <a href="tel:+18095675559" class="sch-support-cta__call"><i data-lucide="phone" class="h-4 w-4"></i><?= e(APP_PHONE) ?></a>
                    <a href="https://wa.me/<?= APP_WHATSAPP ?>" class="sch-support-cta__wa"><i data-lucide="message-circle" class="h-4 w-4"></i>WhatsApp</a>
                </div>
            </div>
        </div>

        <form method="post" class="sch-public-form" data-reveal="right" data-reveal-delay="100">
            <?= csrf_field() ?>
            <div class="sch-section-head sch-section-head--compact">
                <div>
                    <span class="sch-eyebrow">Nuevo ticket</span>
                    <h2 style="font-size:1.5rem">Reporte de soporte</h2>
                </div>
            </div>
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
                    <span>Telefono</span>
                    <input name="phone" autocomplete="tel">
                </label>
                <label class="sch-field">
                    <span>Equipo o sistema</span>
                    <input name="equipment" placeholder="Ej. manifold, lampara, red de gases">
                </label>
                <label class="sch-field">
                    <span>Serie o ubicacion</span>
                    <input name="serial" placeholder="Serie, sala, piso o area">
                </label>
                <label class="sch-field sch-field--full">
                    <span>Prioridad</span>
                    <select name="priority">
                        <option>Baja</option>
                        <option selected>Media</option>
                        <option>Alta</option>
                        <option>Critica</option>
                    </select>
                </label>
                <label class="sch-field sch-field--full">
                    <span class="required">Descripcion del problema</span>
                    <textarea name="description" required rows="6" placeholder="Describe sintomas, hora aproximada, alarmas, codigos y acciones realizadas."></textarea>
                </label>
            </div>
            <div class="sch-form-actions mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-6 text-sch-muted">Los campos marcados con * son obligatorios para abrir el ticket.</p>
                <button class="sch-btn-primary" type="submit"><i data-lucide="send" class="h-4 w-4"></i>Registrar ticket</button>
            </div>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
