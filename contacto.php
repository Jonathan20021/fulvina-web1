<?php
require_once __DIR__ . '/includes/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $company = trim((string) ($_POST['company'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? 'Cotizacion'));

    if ($name === '' || $email === '' || $message === '') {
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
$pageImage = asset('assets/media/2-1.png');
require_once __DIR__ . '/includes/public_header.php';
?>

<section class="sch-page-hero">
    <div class="container-sch sch-page-hero__inner">
        <div data-reveal="left">
            <span class="sch-eyebrow">Contacto comercial</span>
            <h1>Solicita cotizacion, soporte o alcance tecnico para tu institucion</h1>
            <p>El equipo de SCH recibe la solicitud como lead comercial y puede convertirla en cotizacion, ticket o seguimiento interno desde el CRM.</p>
            <div class="sch-page-hero__actions">
                <a href="#cotizar" class="sch-btn-primary"><i data-lucide="file-plus-2" class="h-5 w-5"></i>Solicitar cotizacion</a>
                <a href="<?= url('soporte.php') ?>" class="sch-btn-outline-green"><i data-lucide="headphones" class="h-5 w-5"></i>Soporte tecnico</a>
            </div>
        </div>
        <div class="sch-page-hero__media" data-reveal="right" data-reveal-delay="120">
            <img src="<?= asset('assets/media/2-1.png') ?>" alt="Area hospitalaria equipada por SCH MEDICOS">
        </div>
    </div>
</section>

<section class="sch-section sch-section--white">
    <div class="container-sch sch-contact-layout">
        <div data-reveal="left">
            <div class="sch-section-head sch-section-head--compact">
                <div>
                    <span class="sch-eyebrow">Canales</span>
                    <h2 style="font-size:1.7rem">Donde encontrarnos</h2>
                </div>
            </div>
            <div class="sch-contact-stack">
                <article class="sch-contact-card">
                    <i data-lucide="map-pin" class="h-6 w-6"></i>
                    <div>
                        <h2>Santo Domingo</h2>
                        <p>Calle Ortega y Gasset #24, Ensanche La Fe</p>
                        <a href="tel:+18095675559"><?= e(APP_PHONE) ?></a>
                    </div>
                </article>
                <article class="sch-contact-card">
                    <i data-lucide="warehouse" class="h-6 w-6"></i>
                    <div>
                        <h2>Miami Warehouse</h2>
                        <p>11119 NW 122 ST, Medley, Florida 33178 USA</p>
                        <a href="tel:+13055974090">+1 (305) 597-4090</a>
                    </div>
                </article>
                <article class="sch-contact-card">
                    <i data-lucide="mail" class="h-6 w-6"></i>
                    <div>
                        <h2>Correo comercial</h2>
                        <p>Solicitudes de cotizacion, soporte y proyectos.</p>
                        <a href="mailto:<?= e(APP_EMAIL) ?>"><?= e(APP_EMAIL) ?></a>
                    </div>
                </article>
                <article class="sch-contact-card">
                    <i data-lucide="clock-3" class="h-6 w-6"></i>
                    <div>
                        <h2>Horario</h2>
                        <p>Lunes a viernes, 8:00 am - 6:00 pm. Soporte critico por canal directo.</p>
                    </div>
                </article>
            </div>
        </div>

        <form id="cotizar" method="post" class="sch-public-form" data-reveal="right" data-reveal-delay="100">
            <?= csrf_field() ?>
            <div class="sch-section-head sch-section-head--compact">
                <div>
                    <span class="sch-eyebrow">Solicitud</span>
                    <h2 style="font-size:1.5rem">Cuentanos que necesitas</h2>
                </div>
            </div>
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
            <div class="sch-form-actions mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-6 text-sch-muted">La solicitud queda disponible en reportes y seguimiento comercial.</p>
                <button type="submit" class="sch-btn-primary"><i data-lucide="send" class="h-4 w-4"></i>Enviar solicitud</button>
            </div>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
