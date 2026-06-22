<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/data/sch.php';

$pageTitle = 'SCH MEDICOS | Ingeniería hospitalaria, gases medicinales y equipos médicos';
$pageDescription = 'SCH MEDICOS: diseño, instalación, certificación y soporte técnico de gases medicinales, equipos médicos e ingeniería hospitalaria para clínicas y hospitales desde 1995.';
$pageImage = asset('assets/media/og-cover.png');
$bodyClass = 'sx';
$pageStyles = ['assets/css/site-v2.css'];
$pageFontsGeist = true;
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'MedicalBusiness',
    'name' => 'SCH MEDICOS',
    'description' => $pageDescription,
    'url' => current_url(),
    'logo' => asset(APP_LOGO),
    'email' => APP_EMAIL,
    'telephone' => APP_PHONE,
    'foundingDate' => '1995-01',
    'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => 'Calle Ortega y Gasset #24, Ensanche La Fe',
        'addressLocality' => 'Santo Domingo',
        'addressCountry' => 'DO',
    ],
];

// Years in business, computed from the founding year so it never goes stale.
$yearsActive = max(1, (int) date('Y') - (int) APP_FOUNDED);

$capCodes = ['G-01', 'E-02', 'P-03'];
$capLabels = ['Gases medicinales', 'Equipamiento médico', 'Paredes y protección'];

// Project register: two featured + the rest as a hairline-ruled list.
$featuredIdx = [0, 5];
$registerProjects = [];
foreach ($projects as $i => $p) {
    if (!in_array($i, $featuredIdx, true)) {
        $registerProjects[$i] = $p;
    }
}

require_once __DIR__ . '/includes/public_header.php';
?>

<!-- 1) HERO -Dossier cover -->
<section class="sx-hero" aria-label="SCH MEDICOS">
    <div class="sx-hero__grid">
        <div class="sx-hero__copy">
            <h1 class="sx-hero__title">
                <span class="ln"><span>Ingeniería hospitalaria,</span></span>
                <span class="ln"><span>gases medicinales y equipos.</span></span>
            </h1>
            <p class="sx-hero__qual">Diseño, instalación, certificación y soporte técnico para clínicas y hospitales del sector público y privado en el Caribe.</p>
            <div class="sx-hero__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sx-btn"><i data-lucide="clipboard-pen-line"></i>Solicitar cotización</a>
                <a href="<?= url('proyectos.php') ?>" class="sx-link">Ver proyectos<i data-lucide="arrow-right"></i></a>
            </div>
            <div class="sx-hero__ledger">
                <div class="sx-hero__lcell"><span class="sx-hero__lnum"><?= $yearsActive ?></span><span class="sx-hero__llabel">Años</span></div>
                <div class="sx-hero__lcell"><span class="sx-hero__lnum">600+</span><span class="sx-hero__llabel">Proyectos</span></div>
                <div class="sx-hero__lcell"><span class="sx-hero__lnum sx-hero__lnum--spec">NFPA 99<br>ISO 7396-1</span><span class="sx-hero__llabel">Cumplimiento</span></div>
            </div>
        </div>
        <div class="sx-hero__media">
            <img class="sx-hero__photo" src="<?= asset('assets/media/2-1.png') ?>" alt="Área hospitalaria equipada y certificada por SCH MEDICOS" width="900" height="600" fetchpriority="high" decoding="async">
            <div class="sx-hero__cap"><b>REGISTRO</b> Sistema central de gases, Santo Domingo</div>
        </div>
    </div>
</section>

<!-- 2) AUTHORITY -Heritage ledger -->
<section class="sx-ledger sx-sec sx-sec--tight" aria-label="Trayectoria">
    <div class="sx-container">
        <div class="sx-ledger__grid">
            <div class="sx-ledger__anchorwrap">
                <div class="sx-ledger__anchor"><?= $yearsActive ?></div>
                <div class="sx-ledger__anchorlabel">Años &middot; Desde <?= e(APP_FOUNDED) ?></div>
            </div>
            <div data-reveal="fade">
                <div class="sx-ledger__facts">
                    <div class="sx-fact"><div class="sx-fact__num">600+</div><div class="sx-fact__label">Proyectos ejecutados</div></div>
                    <div class="sx-fact"><div class="sx-fact__num">2</div><div class="sx-fact__label">Sedes: Sto. Dgo. y Miami</div></div>
                    <div class="sx-fact"><div class="sx-fact__num">24/7</div><div class="sx-fact__label">Soporte técnico</div></div>
                    <div class="sx-fact sx-fact--std"><div class="sx-fact__std">NFPA 99 / ISO 7396-1</div><div class="sx-fact__label">Cumplimiento</div></div>
                </div>
                <div class="sx-ledger__measure" aria-hidden="true"><div class="sx-measure"></div></div>
                <div class="sx-ledger__offices">
                    <span class="sx-office"><b>Santo Domingo</b> &middot; Calle Ortega y Gasset #24, Ens. La Fe</span>
                    <span class="sx-office"><b>Miami, FL</b> &middot; 11119 NW 122 ST, Medley 33178</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 3) CAPABILITIES -Service sheet -->
<section class="sx-sec sx-sec--air" aria-label="Capacidades">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <span class="sx-kicker">Capacidades</span>
            <h2 class="sx-h2">Gases medicinales, equipos y paredes modulares.</h2>
            <p class="sx-lead">Redes de oxígeno, vacío y aire medicinal certificadas NFPA 99 e ISO 7396-1, instaladas y mantenidas por equipo técnico propio.</p>
        </div>
        <div class="sx-caps">
            <?php foreach ($services as $i => $service): ?>
                <article class="sx-cap" data-reveal="fade" data-reveal-delay="<?= $i * 90 ?>">
                    <div class="sx-cap__media">
                        <img src="<?= asset('assets/media/' . $service['image']) ?>" alt="<?= e($capLabels[$i] . ' por SCH MEDICOS') ?>" loading="lazy" decoding="async">
                        <span class="sx-cap__code"><?= e($capCodes[$i]) ?></span>
                    </div>
                    <h3 class="sx-cap__title"><?= e($capLabels[$i]) ?></h3>
                    <ul class="sx-cap__spec">
                        <?php foreach (array_slice($service['items'], 0, 6) as $item): ?>
                            <li><?= e($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= url('servicios.php#' . $service['id']) ?>" class="sx-cap__link">Ver servicio<i data-lucide="arrow-right"></i></a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- 4) PROJECTS -Project register -->
<section class="sx-sec sx-sec--air sx-sec--paper" aria-label="Proyectos">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <h2 class="sx-h2">Registro de proyectos hospitalarios.</h2>
            <p class="sx-lead">Instalaciones, suministros y sistemas centrales entregados para instituciones de salud públicas y privadas en toda la República Dominicana.</p>
        </div>

        <div class="sx-feat" data-reveal>
            <?php foreach ($featuredIdx as $k => $fi): $p = $projects[$fi]; ?>
                <a href="<?= url('proyectos.php') ?>" class="sx-feat__item <?= $k === 0 ? 'sx-feat__item--lead' : '' ?>">
                    <div class="sx-feat__media">
                        <img src="<?= asset('assets/media/' . $p['image']) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async">
                    </div>
                    <h3 class="sx-feat__name"><?= e($p['name']) ?></h3>
                    <p class="sx-feat__meta"><?= e($p['location']) ?> &middot; <?= e($p['date']) ?></p>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="sx-register" data-reveal>
            <?php foreach ($registerProjects as $i => $p): ?>
                <a href="<?= url('proyectos.php') ?>" class="sx-reg">
                    <span class="sx-reg__code"><?= e(sprintf('P-%02d', $i + 1)) ?></span>
                    <span class="sx-reg__name"><?= e($p['name']) ?><i data-lucide="arrow-right"></i></span>
                    <span class="sx-reg__loc"><?= e($p['location']) ?></span>
                    <span class="sx-reg__date"><?= e($p['date']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:2rem" data-reveal>
            <a href="<?= url('proyectos.php') ?>" class="sx-link">Ver todos los proyectos<i data-lucide="arrow-right"></i></a>
        </div>
    </div>
</section>

<!-- 5) HERITAGE -Founding statement -->
<section class="sx-sec" aria-label="Sobre SCH" id="marca">
    <div class="sx-container sx-heritage__grid">
        <h2 class="sx-sr">Sobre SCH MEDICOS</h2>
        <div class="sx-heritage__statement" data-reveal>
            Fundada en enero de 1995 por Fulvio Montisano, SCH conceptualiza, diseña e instala sistemas centrales de gases medicinales e ingeniería hospitalaria.
        </div>
        <figure class="sx-heritage__media" data-reveal>
            <img src="<?= asset('assets/media/6.png') ?>" alt="Instalación hospitalaria ejecutada por SCH MEDICOS" loading="lazy" decoding="async">
        </figure>
        <div class="sx-heritage__facts" data-reveal>
            <span class="sx-hfact"><?= $yearsActive ?> años en el sector salud</span>
            <span class="sx-hfact">Representacion oficial de marcas certificadas</span>
            <span class="sx-hfact">Equipo técnico propio</span>
        </div>
    </div>
</section>

<!-- 6) MARKS -Authorized representations -->
<section class="sx-sec sx-sec--tight sx-sec--paper" aria-label="Marcas representadas">
    <div class="sx-container">
        <h2 class="sx-label" data-reveal>Marcas representadas</h2>
        <div class="sx-marks__wall" data-reveal>
            <?php foreach ($brands as $brand): ?>
                <div class="sx-mark"><img src="<?= asset('assets/media/marks/' . $brand['logo']) ?>" alt="<?= e($brand['name']) ?>" loading="lazy" decoding="async"></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- 6b) RECOGNITIONS -->
<section class="sx-sec sx-sec--air" aria-label="Reconocimientos internacionales">
    <div class="sx-container">
        <div class="sx-sechead" data-reveal>
            <span class="sx-kicker">Reconocimientos</span>
            <h2 class="sx-h2">Reconocimientos internacionales.</h2>
            <p class="sx-lead">Trayectoria avalada por distinciones y membresias de organizaciones empresariales globales.</p>
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
                        <li>Sistemas de gestión para el mercado global</li>
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
                    <p class="sx-award__desc">Pertenecemos a la World Confederation of Businesses, organización global que impulsa el crecimiento de las empresas y empresarios lideres a nivel mundial.</p>
                </div>
            </article>
        </div>
    </div>
</section>

<!-- 7) CTA -Sign-off -->
<section class="sx-signoff sx-sec sx-sec--tight" aria-label="Contacto">
    <div class="sx-container">
        <div class="sx-signoff__inner">
            <h2 class="sx-signoff__h">Dimensionemos tu próximo proyecto hospitalario.</h2>
            <div class="sx-signoff__actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sx-btn sx-btn--ondark"><i data-lucide="clipboard-pen-line"></i>Solicitar cotización</a>
                <a href="tel:+18095675559" class="sx-tel"><i data-lucide="phone"></i><?= e(APP_PHONE) ?></a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
