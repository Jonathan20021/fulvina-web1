<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/data/sch.php';

$pageTitle = 'Productos y servicios SCH MEDICOS | Gases medicinales, equipos e ingenieria hospitalaria';
$pageDescription = 'Servicios de SCH MEDICOS para hospitales y clinicas: gases medicinales, equipos medicos, paredes modulares, proteccion hospitalaria, mantenimiento y certificacion.';
$pageImage = asset('assets/media/og-cover.png');
$bodyClass = 'sx';
$pageStyles = ['assets/css/site-v2.css'];
$pageFontsGeist = true;

$sheetCodes = ['G-01', 'E-02', 'P-03'];
$steps = [
    ['Fase 01', 'Diseno y calculo', 'Levantamiento tecnico, planos, dimensionamiento y seleccion de componentes.'],
    ['Fase 02', 'Instalacion', 'Montaje de sistemas, equipos, redes, cabeceros, manifolds y areas criticas.'],
    ['Fase 03', 'Certificacion', 'Pruebas, puesta en marcha y documentacion para entrega institucional.'],
    ['Fase 04', 'Soporte continuo', 'Mantenimiento preventivo, correctivo y seguimiento por ticket.'],
];

require_once __DIR__ . '/includes/public_header.php';
?>

<!-- COVER -->
<section class="sx-cover" aria-label="Productos y servicios">
    <div class="sx-container sx-cover__grid">
        <div data-reveal>
            <span class="sx-label">Productos y servicios</span>
            <h1 class="sx-cover__title">Sistemas tecnicos para areas clinicas y gases medicinales.</h1>
            <p class="sx-cover__lead">SCH integra diseno, suministro, instalacion, certificacion y soporte para que la infraestructura hospitalaria opere con trazabilidad y respuesta tecnica.</p>
            <div class="sx-cover__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sx-btn"><i data-lucide="clipboard-pen-line"></i>Solicitar cotizacion</a>
                <a href="<?= url('soporte.php') ?>" class="sx-link">Reportar soporte<i data-lucide="arrow-right"></i></a>
            </div>
        </div>
        <div class="sx-cover__media" data-reveal>
            <img src="<?= asset('assets/media/Gases-5.png') ?>" alt="Sistema de gases medicinales certificado por SCH MEDICOS" loading="lazy" decoding="async">
        </div>
    </div>
</section>

<!-- PROCESS LEDGER -->
<section class="sx-sec sx-sec--tight sx-sec--paper" aria-label="Proceso de trabajo">
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

<!-- SERVICE SHEETS -->
<section class="sx-sec sx-sec--air" aria-label="Soluciones">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <span class="sx-kicker">Capacidades</span>
            <h2 class="sx-h2">Tres lineas de servicio, ejecutadas de extremo a extremo.</h2>
        </div>
        <?php foreach ($services as $i => $service): ?>
            <article id="<?= e($service['id']) ?>" class="sx-sheet <?= $i % 2 === 1 ? 'is-rev' : '' ?>" data-reveal>
                <div class="sx-sheet__media">
                    <img src="<?= asset('assets/media/' . $service['image']) ?>" alt="<?= e(image_alt($service['image'], $service['title'])) ?>" loading="lazy" decoding="async">
                </div>
                <div>
                    <div class="sx-sheet__code"><?= e($sheetCodes[$i] ?? 'S-0' . ($i + 1)) ?></div>
                    <h3 class="sx-sheet__title"><?= e($service['title']) ?></h3>
                    <p class="sx-sheet__sum"><?= e($service['summary']) ?></p>
                    <ul class="sx-sheet__spec">
                        <?php foreach ($service['items'] as $item): ?>
                            <li><?= e($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="sx-sheet__cta">
                        <a href="<?= url('contacto.php#cotizar') ?>" class="sx-link">Cotizar este servicio<i data-lucide="arrow-right"></i></a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<!-- EVIDENCE STRIP -->
<section class="sx-sec sx-sec--tight sx-sec--paper" aria-label="Registro fotografico">
    <div class="sx-container">
        <div class="sx-strip" data-reveal>
            <?php foreach (['Equipo-medico-1.png','Equipo-medico-3.png','Gases-2.png','Paredes-1.png','5.png','6.png','9.png','15.png'] as $image): ?>
                <img src="<?= asset('assets/media/' . $image) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async">
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- SIGN-OFF -->
<section class="sx-signoff sx-sec sx-sec--tight" aria-label="Contacto">
    <div class="sx-container">
        <div class="sx-signoff__inner">
            <h2 class="sx-signoff__h">Dimensionemos tu proximo proyecto hospitalario.</h2>
            <div class="sx-signoff__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sx-btn sx-btn--ondark"><i data-lucide="clipboard-pen-line"></i>Solicitar cotizacion</a>
                <a href="tel:+18095675559" class="sx-tel"><i data-lucide="phone"></i><?= e(APP_PHONE) ?></a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
