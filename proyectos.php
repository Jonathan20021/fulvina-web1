<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/data/sch.php';

$pageTitle = 'Proyectos SCH MEDICOS | Hospitales, emergencias y centros de salud';
$pageDescription = 'Proyectos de SCH MEDICOS en Republica Dominicana: hospitales, emergencias, gases medicinales, equipamiento medico, paredes modulares e ingenieria hospitalaria.';
$pageImage = asset('assets/media/og-cover.png');
$bodyClass = 'sx';
$pageStyles = ['assets/css/site-v2.css'];
$pageFontsGeist = true;

$featuredIdx = [0, 5];
$registerProjects = [];
foreach ($projects as $i => $p) {
    if (!in_array($i, $featuredIdx, true)) {
        $registerProjects[$i] = $p;
    }
}

require_once __DIR__ . '/includes/public_header.php';
?>

<!-- COVER -->
<section class="sx-cover" aria-label="Proyectos">
    <div class="sx-container sx-cover__grid">
        <div data-reveal>
            <span class="sx-label">Portafolio &middot; Desde 1995</span>
            <h1 class="sx-cover__title">Proyectos hospitalarios con control tecnico y evidencia real.</h1>
            <p class="sx-cover__lead">Una seleccion de instalaciones, suministros y sistemas ejecutados para instituciones publicas y privadas en Republica Dominicana.</p>
            <div class="sx-cover__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sx-btn"><i data-lucide="clipboard-pen-line"></i>Solicitar cotizacion</a>
                <a href="<?= url('servicios.php') ?>" class="sx-link">Ver servicios<i data-lucide="arrow-right"></i></a>
            </div>
        </div>
        <div class="sx-cover__media" data-reveal>
            <img src="<?= asset('assets/media/hospital-de-santiago.png') ?>" alt="Hospital equipado por SCH MEDICOS" loading="lazy" decoding="async">
        </div>
    </div>
</section>

<!-- REGISTER -->
<section class="sx-sec sx-sec--air" aria-label="Registro de proyectos">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <span class="sx-kicker">Instituciones</span>
            <h2 class="sx-h2">Registro de trabajos entregados.</h2>
            <p class="sx-lead">Cada proyecto prioriza instalaciones reales con ubicacion, periodo de ejecucion y alcance tecnico.</p>
        </div>

        <div class="sx-feat" data-reveal>
            <?php foreach ($featuredIdx as $k => $fi): $p = $projects[$fi]; ?>
                <article class="sx-feat__item sx-feat__item--static <?= $k === 0 ? 'sx-feat__item--lead' : '' ?>">
                    <div class="sx-feat__media">
                        <img src="<?= asset('assets/media/' . $p['image']) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async">
                    </div>
                    <h3 class="sx-feat__name"><?= e($p['name']) ?></h3>
                    <p class="sx-feat__meta"><?= e($p['location']) ?> &middot; <?= e($p['date']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="sx-register" data-reveal>
            <?php foreach ($registerProjects as $i => $p): ?>
                <div class="sx-reg sx-reg--static">
                    <span class="sx-reg__code"><?= e(sprintf('P-%02d', $i + 1)) ?></span>
                    <span class="sx-reg__name"><?= e($p['name']) ?></span>
                    <span class="sx-reg__loc"><?= e($p['location']) ?></span>
                    <span class="sx-reg__date"><?= e($p['date']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- EVIDENCE STRIP -->
<section class="sx-sec sx-sec--tight sx-sec--paper" aria-label="Instalaciones documentadas">
    <div class="sx-container">
        <div class="sx-strip" data-reveal>
            <?php foreach (['1.png','2-1.png','3.png','6.png','9.png','15.png','Gases-1.png','Paredes-1.png'] as $image): ?>
                <img src="<?= asset('assets/media/' . $image) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async">
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- SIGN-OFF -->
<section class="sx-signoff sx-sec sx-sec--tight" aria-label="Contacto">
    <div class="sx-container">
        <div class="sx-signoff__inner">
            <h2 class="sx-signoff__h">Tu institucion es la proxima. Dimensionemos el alcance.</h2>
            <div class="sx-signoff__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sx-btn sx-btn--ondark"><i data-lucide="clipboard-pen-line"></i>Solicitar cotizacion</a>
                <a href="tel:+18095675559" class="sx-tel"><i data-lucide="phone"></i><?= e(APP_PHONE) ?></a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
