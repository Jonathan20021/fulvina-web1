<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/data/sch.php';

$pageTitle = 'Proyectos SCH MEDICOS | Hospitales, emergencias y centros de salud';
$pageDescription = 'Proyectos de SCH MEDICOS en Republica Dominicana: hospitales, emergencias, gases medicinales, equipamiento medico, paredes modulares e ingenieria hospitalaria.';
$pageImage = asset('assets/media/hospital-de-santiago.png');
require_once __DIR__ . '/includes/public_header.php';
?>

<section class="sch-page-hero">
    <div class="container-sch sch-page-hero__inner">
        <div data-reveal="left">
            <span class="sch-eyebrow">Proyectos &middot; Desde 1995</span>
            <h1>Proyectos hospitalarios entregados con control tecnico y evidencia real</h1>
            <p>Una seleccion de instalaciones, suministros y sistemas ejecutados para instituciones publicas y privadas en Republica Dominicana.</p>
            <div class="sch-page-hero__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary"><i data-lucide="building-2" class="h-5 w-5"></i>Cotizar proyecto</a>
                <a href="<?= url('servicios.php') ?>" class="sch-btn-outline-green"><i data-lucide="list-checks" class="h-5 w-5"></i>Ver servicios</a>
            </div>
        </div>
        <div class="sch-page-hero__media" data-reveal="right" data-reveal-delay="120">
            <img src="<?= asset('assets/media/hospital-de-santiago.png') ?>" alt="Hospital equipado por SCH MEDICOS">
        </div>
    </div>
</section>

<section class="sch-section sch-section--white" style="padding-top:0">
    <div class="container-sch" style="margin-top:-2.2rem;position:relative;z-index:5">
        <div class="sch-proof-band" aria-label="Evidencia de proyectos" data-reveal>
            <article>
                <span>Trayectoria</span>
                <strong>Desde 1995</strong>
                <p>Experiencia en infraestructura hospitalaria y equipamiento medico.</p>
            </article>
            <article>
                <span>Alcance</span>
                <strong>Publico y privado</strong>
                <p>Hospitales, clinicas, emergencias y centros especializados.</p>
            </article>
            <article>
                <span>Entrega</span>
                <strong>Sistemas completos</strong>
                <p>Gases, areas criticas, equipos, paredes, protecciones y soporte.</p>
            </article>
            <article>
                <span>Seguimiento</span>
                <strong>CRM operativo</strong>
                <p>Clientes, equipos, garantias, tickets y cotizaciones conectados.</p>
            </article>
        </div>
    </div>
</section>

<section class="sch-section sch-section--white" style="padding-top:1rem">
    <div class="container-sch">
        <div class="sch-section-head" data-reveal>
            <div>
                <span class="sch-eyebrow">Instituciones</span>
                <h2>Trabajos destacados y verificables</h2>
            </div>
            <p>Cada proyecto prioriza instalaciones reales: ubicacion, periodo de ejecucion y alcance tecnico.</p>
        </div>
        <div class="sch-project-grid">
            <?php foreach ($projects as $i => $project): ?>
                <article class="sch-project-card" data-reveal="scale" data-reveal-delay="<?= ($i % 4) * 80 ?>">
                    <div class="sch-project-card__media">
                        <img src="<?= asset('assets/media/' . $project['image']) ?>" alt="<?= e($project['name']) ?>" loading="lazy">
                    </div>
                    <div class="sch-project-card__body">
                        <div class="sch-meta-row">
                            <span><i data-lucide="calendar" class="h-3.5 w-3.5"></i><?= e($project['date']) ?></span>
                            <span><i data-lucide="map-pin" class="h-3.5 w-3.5"></i><?= e($project['location']) ?></span>
                        </div>
                        <h2><?= e($project['name']) ?></h2>
                        <p><?= e($project['work']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sch-section sch-section--page">
    <div class="container-sch">
        <div class="sch-section-head" data-reveal>
            <div>
                <span class="sch-eyebrow">Registro fotografico</span>
                <h2>Instalaciones documentadas</h2>
            </div>
            <p>Imagenes reales de instalaciones, equipos y sistemas entregados por SCH.</p>
        </div>
        <div class="sch-gallery-grid" data-reveal>
            <?php foreach (['1.png','2-1.png','3.png','6.png','9.png','15.png','Gases-1.png','Paredes-1.png'] as $image): ?>
                <img src="<?= asset('assets/media/' . $image) ?>" alt="<?= e(image_alt($image, 'Instalacion SCH MEDICOS')) ?>" loading="lazy">
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sch-section sch-section--white">
    <div class="container-sch">
        <div class="sch-cta-band" data-reveal>
            <div>
                <span class="sch-eyebrow sch-eyebrow--light">Tu institucion es la proxima</span>
                <h2>Listos para equipar o intervenir tu area clinica</h2>
                <p>Solicita una visita tecnica o una cotizacion y un especialista de SCH evaluara el alcance de tu proyecto.</p>
            </div>
            <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary"><i data-lucide="send" class="h-4 w-4"></i>Solicitar cotizacion</a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
