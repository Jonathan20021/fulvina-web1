<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/data/sch.php';

$pageTitle = 'Sobre SCH MEDICOS | Ingenieria hospitalaria desde 1995';
$pageDescription = 'SCH (Servicios para Clinicas y Hospitales) fue fundada en 1995 en Santo Domingo. Conoce nuestra historia, mision, vision, valores, normas, estandares y reconocimientos internacionales.';
$pageImage = asset('assets/media/og-cover.png');
$bodyClass = 'sx';
$pageStyles = ['assets/css/site-v2.css'];
$pageFontsGeist = true;
$canonical = current_url();

$milestones = [
    ['1995', 'Fundacion en Santo Domingo por el Lic. Fulvio Montisano, con el diseño e instalacion de sistemas centrales de gases medicinales.'],
    ['2000', 'Apertura de la primera sucursal en Santiago de los Caballeros.'],
    ['2008', 'Galardon en The Bizz Awards como empresa lider del sector.'],
    ['Hoy', 'Mas de 500 proyectos ejecutados y dos sedes operativas: Santo Domingo y Miami.'],
];

$values = [
    ['V-01', 'Integridad'],
    ['V-02', 'Confidencialidad'],
    ['V-03', 'Trabajo en equipo'],
    ['V-04', 'Innovacion'],
    ['V-05', 'Orientacion al servicio'],
];

$standards = [
    ['NFPA 99', 'Codigo de gases medicinales'],
    ['HTM 2002', 'Estandar hospitalario (UK)'],
    ['ISO 7396-1', 'Redes de gases medicos'],
    ['ISO 9001:2000', 'Gestion de calidad'],
    ['ISO 13485:2003', 'Dispositivos medicos'],
    ['ISO 14001:2005', 'Gestion ambiental'],
    ['ASTM E84', 'Reaccion superficial al fuego'],
    ['CE Mark', 'Conformidad europea'],
    ['SGS', 'Certificacion ISO'],
    ['MSP / OPS / OMS', 'Guia de diseño de establecimientos de salud'],
];

require_once __DIR__ . '/includes/public_header.php';
?>

<!-- COVER -->
<section class="sx-cover" aria-label="Sobre SCH">
    <div class="sx-container sx-cover__grid">
        <div data-reveal>
            <span class="sx-label">Sobre SCH</span>
            <h1 class="sx-cover__title">Servicios para clinicas y hospitales desde 1995.</h1>
            <p class="sx-cover__lead">SCH fue fundada en Santo Domingo en enero de 1995 para ofrecer soluciones integrales en el equipamiento del area hospitalaria, tanto en el sector publico como en el privado.</p>
            <div class="sx-cover__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sx-btn"><i data-lucide="clipboard-pen-line"></i>Solicitar cotizacion</a>
                <a href="<?= url('proyectos.php') ?>" class="sx-link">Ver proyectos<i data-lucide="arrow-right"></i></a>
            </div>
        </div>
        <div class="sx-cover__media" data-reveal>
            <img src="<?= asset('assets/media/5.png') ?>" alt="Area hospitalaria equipada por SCH MEDICOS" loading="lazy" decoding="async">
        </div>
    </div>
</section>

<!-- HISTORY + TIMELINE -->
<section class="sx-sec sx-sec--paper" aria-label="Historia">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <h2 class="sx-h2">Tres decadas equipando el sector salud.</h2>
            <p class="sx-lead">Lo que comenzo como diseño e instalacion de gases medicinales se amplio a ingenieria hospitalaria, consultoria, mantenimiento y equipos especializados.</p>
        </div>
        <div class="sx-timeline" data-reveal>
            <?php foreach ($milestones as $m): ?>
                <div class="sx-tl">
                    <div class="sx-tl__year"><?= e($m[0]) ?></div>
                    <div class="sx-tl__text"><?= e($m[1]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- MISSION / VISION -->
<section class="sx-sec sx-sec--graphite" aria-label="Mision y vision">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <h2 class="sx-h2">Mision y vision.</h2>
        </div>
        <div class="sx-mv">
            <div class="sx-mv__item" data-reveal>
                <div class="sx-mv__label">Mision</div>
                <p class="sx-mv__text">Lograr la satisfaccion de nuestros clientes en los mercados nacionales e internacionales y ofrecer servicios que apoyen el logro de sus objetivos estrategicos.</p>
            </div>
            <div class="sx-mv__item" data-reveal data-reveal-delay="100">
                <div class="sx-mv__label">Vision</div>
                <p class="sx-mv__text">Ser una empresa lider en la comercializacion de equipos medicos y el desarrollo de proyectos hospitalarios, con las mejores practicas y estandares internacionales, basados en una ejecucion etica y profesional.</p>
            </div>
        </div>
    </div>
</section>

<!-- VALUES -->
<section class="sx-sec" aria-label="Valores">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <h2 class="sx-h2">Valores.</h2>
        </div>
        <div class="sx-values" data-reveal>
            <?php foreach ($values as $v): ?>
                <div class="sx-value">
                    <div class="sx-value__code"><?= e($v[0]) ?></div>
                    <div class="sx-value__name"><?= e($v[1]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- STANDARDS -->
<section class="sx-sec sx-sec--paper" aria-label="Normas y estandares">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <span class="sx-kicker">Cumplimiento</span>
            <h2 class="sx-h2">Normas y estandares.</h2>
            <p class="sx-lead">Diseñamos, instalamos y certificamos conforme a estandares nacionales e internacionales del sector salud.</p>
        </div>
        <div class="sx-stds" data-reveal>
            <?php foreach ($standards as $s): ?>
                <div class="sx-std">
                    <div class="sx-std__code"><?= e($s[0]) ?></div>
                    <div class="sx-std__note"><?= e($s[1]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- RECOGNITIONS -->
<section class="sx-sec sx-sec--air" aria-label="Reconocimientos internacionales">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <span class="sx-kicker">Reconocimientos</span>
            <h2 class="sx-h2">Reconocimientos internacionales.</h2>
        </div>
        <div class="sx-awards">
            <article class="sx-award" data-reveal>
                <div class="sx-award__logo"><img src="<?= asset('assets/media/the-bizz.jpg') ?>" alt="The Bizz Awards 2008" loading="lazy" decoding="async"></div>
                <div class="sx-award__body">
                    <span class="sx-award__meta">2008 &middot; The Bizz Awards</span>
                    <h3 class="sx-award__title">Empresa lider del sector</h3>
                    <p class="sx-award__desc">Galardon en la premiacion The Bizz Awards a la empresa lider del sector, avalado por atributos como:</p>
                    <ul class="sx-award__spec">
                        <li>Liderazgo empresarial</li>
                        <li>Calidad en productos y servicios</li>
                        <li>Innovacion</li>
                        <li>Sistemas de gestion para el mercado global</li>
                        <li>Creatividad empresarial</li>
                        <li>Apoyo social</li>
                    </ul>
                </div>
            </article>
            <article class="sx-award" data-reveal data-reveal-delay="100">
                <div class="sx-award__logo"><img src="<?= asset('assets/media/Member.jpg') ?>" alt="Miembro de la World Confederation of Businesses" loading="lazy" decoding="async"></div>
                <div class="sx-award__body">
                    <span class="sx-award__meta">Miembro &middot; WCB</span>
                    <h3 class="sx-award__title">World Confederation of Businesses</h3>
                    <p class="sx-award__desc">Pertenecemos a la World Confederation of Businesses, organizacion global que impulsa el crecimiento de las empresas y empresarios lideres a nivel mundial.</p>
                </div>
            </article>
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
