<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/data/sch.php';

$pageTitle = 'Productos y servicios SCH MEDICOS | Gases medicinales, equipos e ingenieria hospitalaria';
$pageDescription = 'Servicios de SCH MEDICOS para hospitales y clinicas: gases medicinales, equipos medicos, paredes modulares, proteccion hospitalaria, mantenimiento y certificacion.';
$pageImage = asset('assets/media/Gases-5.png');
require_once __DIR__ . '/includes/public_header.php';
?>

<section class="sch-page-hero">
    <div class="container-sch sch-page-hero__inner">
        <div data-reveal="left">
            <span class="sch-eyebrow">Productos y servicios</span>
            <h1>Soluciones tecnicas para areas clinicas, gases y equipamiento hospitalario</h1>
            <p>SCH integra diseno, suministro, instalacion, certificacion y soporte para que la infraestructura hospitalaria opere con trazabilidad y respuesta tecnica.</p>
            <div class="sch-page-hero__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary"><i data-lucide="clipboard-pen-line" class="h-5 w-5"></i>Solicitar cotizacion</a>
                <a href="<?= url('soporte.php') ?>" class="sch-btn-outline-green"><i data-lucide="headphones" class="h-5 w-5"></i>Reportar soporte</a>
            </div>
        </div>
        <div class="sch-page-hero__media" data-reveal="right" data-reveal-delay="120">
            <img src="<?= asset('assets/media/Gases-5.png') ?>" alt="Instalacion hospitalaria y sistemas de gases medicinales SCH MEDICOS">
        </div>
    </div>
</section>

<section class="sch-section sch-section--white" style="padding-top:0">
    <div class="container-sch" style="margin-top:-2.2rem;position:relative;z-index:5">
        <div class="sch-proof-band" aria-label="Capacidades de servicio" data-reveal>
            <article>
                <span>Fase 01</span>
                <strong>Diseno y calculo</strong>
                <p>Levantamiento tecnico, planos, dimensionamiento y seleccion de componentes.</p>
            </article>
            <article>
                <span>Fase 02</span>
                <strong>Instalacion</strong>
                <p>Montaje de sistemas, equipos, redes, cabeceros, manifolds y areas criticas.</p>
            </article>
            <article>
                <span>Fase 03</span>
                <strong>Certificacion</strong>
                <p>Pruebas, puesta en marcha y documentacion para entrega institucional.</p>
            </article>
            <article>
                <span>Fase 04</span>
                <strong>Soporte continuo</strong>
                <p>Mantenimiento preventivo, correctivo y seguimiento por ticket en el CRM.</p>
            </article>
        </div>
    </div>
</section>

<section class="sch-section sch-section--white" style="padding-top:1rem">
    <div class="container-sch">
        <?php foreach ($services as $index => $service): ?>
            <article id="<?= e($service['id']) ?>" class="sch-service-block <?= $index % 2 === 1 ? 'is-reversed' : '' ?>" data-reveal>
                <div class="sch-service-block__media">
                    <img src="<?= asset('assets/media/' . $service['image']) ?>" alt="<?= e(image_alt($service['image'], $service['title'])) ?>" loading="lazy">
                    <span class="sch-service-block__tag">0<?= $index + 1 ?> / <?= count($services) ?></span>
                </div>
                <div>
                    <span class="sch-eyebrow">Solucion</span>
                    <h2><?= e($service['title']) ?></h2>
                    <p><?= e($service['summary']) ?></p>
                    <div class="sch-check-grid">
                        <?php foreach ($service['items'] as $item): ?>
                            <span><i data-lucide="check" class="h-4 w-4"></i><?= e($item) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-6">
                        <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary"><i data-lucide="arrow-right" class="h-4 w-4"></i>Cotizar este servicio</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="sch-section sch-section--page">
    <div class="container-sch">
        <div class="sch-section-head" data-reveal>
            <div>
                <span class="sch-eyebrow">Evidencia visual</span>
                <h2>Equipos, areas y sistemas con registro fotografico</h2>
            </div>
            <p>Seleccion visual de equipamiento, sistemas de gases, paredes y areas clinicas intervenidas por SCH.</p>
        </div>
        <div class="sch-gallery-grid" data-reveal>
            <?php foreach (['Equipo-medico-1.png','Equipo-medico-3.png','Gases-2.png','Paredes-1.png','5.png','6.png','9.png','15.png'] as $image): ?>
                <img src="<?= asset('assets/media/' . $image) ?>" alt="<?= e(image_alt($image, 'Servicio SCH MEDICOS')) ?>" loading="lazy">
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sch-section sch-section--white">
    <div class="container-sch">
        <div class="sch-cta-band" data-reveal>
            <div>
                <span class="sch-eyebrow sch-eyebrow--light">Un solo flujo de trabajo</span>
                <h2>Necesitas dimensionar un proyecto o resolver una falla?</h2>
                <p>Ventas y soporte trabajan sobre el mismo CRM para que la informacion no se pierda entre cotizacion, instalacion y mantenimiento.</p>
            </div>
            <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary"><i data-lucide="send" class="h-4 w-4"></i>Enviar solicitud</a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
