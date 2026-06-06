<?php
require_once __DIR__ . '/includes/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: bots fill the hidden "website" field; silently drop them.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        redirect('contacto.php#cotizar');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $company = trim((string) ($_POST['company'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? 'Cotizacion'));

    if (!form_throttle_ok('contacto')) {
        flash('warning', 'Recibimos varias solicitudes desde tu conexión. Intenta de nuevo en unos minutos.');
        redirect('contacto.php#cotizar');
    } elseif ($name === '' || $email === '' || $message === '') {
        flash('warning', 'Completa nombre, correo y mensaje.');
    } elseif (db(false) && table_exists('leads')) {
        $stmt = db()->prepare('INSERT INTO leads (name, email, phone, company, type, message, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "nuevo", NOW())');
        $stmt->execute([$name, $email, $phone, $company, $type, $message]);
        flash('success', 'Solicitud registrada. El equipo comercial puede verla en el CRM.');
        redirect('contacto.php#cotizar');
    } else {
        flash('warning', 'Solicitud validada en modo local. Ejecuta install.php para guardarla en MySQL.');
    }
}

$pageTitle = 'Contacto SCH MEDICOS | Solicitar cotizacion de equipos medicos';
$pageDescription = 'Contacta a SCH MEDICOS en Santo Domingo o Miami para cotizar equipos medicos, gases medicinales, instalaciones hospitalarias y soporte tecnico.';
$pageImage = asset('assets/media/og-cover.png');
$bodyClass = 'sx';
$pageStyles = ['assets/css/site-v2.css'];
$pageFontsGeist = true;

$steps = [
    ['Paso 01', 'Recibimos la solicitud', 'Tu mensaje entra al CRM como lead comercial con datos de contacto.'],
    ['Paso 02', 'Clasificacion', 'Ventas la convierte en cotizacion, ticket o proyecto segun el tipo.'],
    ['Paso 03', 'Respuesta tecnica', 'Asignamos responsable y preparamos alcance, equipos y tiempos.'],
    ['Paso 04', 'Seguimiento', 'El caso queda trazable en reportes y seguimiento comercial.'],
];

require_once __DIR__ . '/includes/public_header.php';
?>

<!-- COVER (graphite sign-off band) -->
<section class="sx-cover sx-cover--graphite sx-sec sx-sec--tight" aria-label="Contacto">
    <div class="sx-container">
        <span class="sx-kicker sx-kicker--light">Contacto comercial</span>
        <h1 class="sx-cover__title">Solicita cotizacion, soporte o alcance tecnico.</h1>
        <p class="sx-cover__lead">El equipo de SCH recibe la solicitud como lead comercial y puede convertirla en cotizacion, ticket o seguimiento interno desde el CRM.</p>
        <div class="sx-cover__actions">
            <a href="#cotizar" class="sx-btn sx-btn--ondark"><i data-lucide="file-plus-2"></i>Solicitar cotizacion</a>
            <a href="<?= url('soporte.php') ?>" class="sx-link sx-link--light">Soporte tecnico<i data-lucide="arrow-right"></i></a>
        </div>
    </div>
</section>

<!-- PROCESS LEDGER -->
<section class="sx-sec sx-sec--tight sx-sec--paper" aria-label="Como avanzamos tu solicitud">
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

<!-- CHANNELS + FORM -->
<section class="sx-sec" aria-label="Canales y solicitud">
    <div class="sx-container sx-work">
        <div data-reveal="left">
            <h2 class="sx-h2" style="font-size:var(--t-h3)">Donde encontrarnos</h2>
            <div class="sx-channels" style="margin-top:1.4rem">
                <div class="sx-channel">
                    <i data-lucide="map-pin"></i>
                    <div>
                        <h3>Santo Domingo</h3>
                        <p>Calle Ortega y Gasset #24, Ensanche La Fe</p>
                        <a href="tel:+18095675559"><?= e(APP_PHONE) ?></a>
                    </div>
                </div>
                <div class="sx-channel">
                    <i data-lucide="warehouse"></i>
                    <div>
                        <h3>Miami Warehouse</h3>
                        <p>11119 NW 122 ST, Medley, Florida 33178 USA</p>
                        <a href="tel:+13055974090"><?= e(APP_PHONE_US) ?></a>
                    </div>
                </div>
                <div class="sx-channel">
                    <i data-lucide="mail"></i>
                    <div>
                        <h3>Correo comercial</h3>
                        <p>Solicitudes de cotizacion, soporte y proyectos.</p>
                        <a href="mailto:<?= e(APP_EMAIL) ?>"><?= e(APP_EMAIL) ?></a>
                    </div>
                </div>
                <div class="sx-channel">
                    <i data-lucide="clock-3"></i>
                    <div>
                        <h3>Horario</h3>
                        <p>Lunes a viernes, 8:00 am a 6:00 pm. Soporte critico por canal directo.</p>
                    </div>
                </div>
            </div>
        </div>

        <form id="cotizar" method="post" class="sch-public-form" data-reveal="right" data-reveal-delay="100">
            <?= csrf_field() ?>
            <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
            <h2 class="sx-h2" style="font-size:1.4rem;margin-bottom:1.2rem">Cuentanos que necesitas</h2>
            <div class="sch-form-grid">
                <label class="sch-field">
                    <span class="required">Nombre</span>
                    <input name="name" required autocomplete="name">
                </label>
                <label class="sch-field">
                    <span>Empresa</span>
                    <input name="company" autocomplete="organization">
                </label>
                <label class="sch-field">
                    <span class="required">Correo</span>
                    <input type="email" name="email" required autocomplete="email">
                </label>
                <label class="sch-field">
                    <span>Telefono</span>
                    <input name="phone" autocomplete="tel">
                </label>
                <label class="sch-field sch-field--full">
                    <span>Tipo de solicitud</span>
                    <select name="type">
                        <option>Cotizacion</option>
                        <option>Soporte tecnico</option>
                        <option>Proyecto hospitalario</option>
                        <option>Mantenimiento</option>
                    </select>
                </label>
                <label class="sch-field sch-field--full">
                    <span class="required">Mensaje</span>
                    <textarea name="message" required rows="6" placeholder="Describe equipos, cantidades, area clinica, ubicacion, urgencia o alcance tecnico."></textarea>
                </label>
            </div>
            <div class="sch-form-actions">
                <p>La solicitud queda disponible en reportes y seguimiento comercial.</p>
                <button type="submit" class="sch-btn-primary"><i data-lucide="send" class="h-4 w-4"></i>Enviar solicitud</button>
            </div>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
